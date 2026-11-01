<?php

declare(strict_types=1);

namespace App\Services;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cliente da API pública do Querido Diário, da Open Knowledge Brasil.
 *
 * Documentação: https://docs.queridodiario.ok.org.br
 * Os dados são CC-BY 4.0, então a fonte precisa ser citada onde forem exibidos.
 */
class QueridoDiarioClient
{
    /**
     * Ordenação obrigatória em qualquer coleta cronológica.
     *
     * O padrão da API é "relevance". Paginar por offset em cima de ordenação
     * por relevância devolve resultado instável: registros se repetem entre
     * páginas e outros somem. Pedindo um diário de Campinas sem filtro de data,
     * a API devolveu um de 2022 em vez do mais recente.
     */
    public const ORDEM_DATA_CRESCENTE = 'ascending_date';

    public const ORDEM_DATA_DECRESCENTE = 'descending_date';

    /** Maior valor de "size" que a API aceita por página (verificado em 31/10/2026). */
    public const TAMANHO_MAXIMO_PAGINA = 1000;

    private const CHAVE_ULTIMA_REQUISICAO = 'qd:ultima-requisicao';

    /** Abaixo disso o prefixo casa com meio Brasil e a busca deixa de ajudar. */
    private const TAMANHO_MINIMO_PREFIXO = 4;

    public function __construct(
        private readonly string $urlBase,
        private readonly int $requisicoesPorMinuto,
        private readonly int $timeout,
        private readonly int $timeoutConexao,
        private readonly int $cacheMinutos,
    ) {}

    /**
     * Procura municípios pelo nome.
     *
     * Atenção: a busca é sensível a acento. "Jaguariúna" encontra, "jaguariuna"
     * devolve lista vazia. Quem chama deve avisar o usuário disso.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarMunicipiosPorNome(string $nome): array
    {
        return $this->obterJson('/cities', ['city_name' => $nome])['cities'] ?? [];
    }

    /**
     * Procura municípios tolerando a falta de acento no termo digitado.
     *
     * A API casa por substring e ignora maiúsculas, mas não ignora acento:
     * "jaguari" encontra Jaguariúna, enquanto "jaguariuna" não encontra nada.
     * Então, quando a busca exata falha, encurtamos o termo letra a letra até
     * cair antes do acento, e aí filtramos o resultado comparando os nomes já
     * sem acento.
     *
     * @return array{municipios: array<int, array<string, mixed>>, termo_usado: string}
     */
    public function buscarMunicipiosTolerandoAcento(string $nome): array
    {
        $municipios = $this->buscarMunicipiosPorNome($nome);

        if ($municipios !== []) {
            return ['municipios' => $municipios, 'termo_usado' => $nome];
        }

        $alvo = $this->semAcento($nome);

        for ($tamanho = mb_strlen($nome) - 1; $tamanho >= self::TAMANHO_MINIMO_PREFIXO; $tamanho--) {
            $prefixo = mb_substr($nome, 0, $tamanho);
            $encontrados = $this->buscarMunicipiosPorNome($prefixo);

            if ($encontrados === []) {
                continue;
            }

            $correspondentes = array_values(array_filter(
                $encontrados,
                fn (array $municipio): bool => $this->semAcento((string) ($municipio['territory_name'] ?? '')) === $alvo,
            ));

            if ($correspondentes !== []) {
                return ['municipios' => $correspondentes, 'termo_usado' => $prefixo];
            }
        }

        return ['municipios' => [], 'termo_usado' => $nome];
    }

    /**
     * Busca um município pelo código IBGE, ou null se não existir.
     *
     * @return array<string, mixed>|null
     */
    public function obterMunicipio(string $codigoIbge): ?array
    {
        $resposta = $this->requisitar('/cities/'.$codigoIbge);

        if ($resposta->notFound()) {
            return null;
        }

        return $resposta->throw()->json('city');
    }

    /**
     * Busca uma página de diários de um município.
     *
     * @return array{total: int, diarios: array<int, array<string, mixed>>}
     */
    public function buscarDiarios(
        string $codigoIbge,
        ?string $desde = null,
        ?string $ate = null,
        int $tamanho = 100,
        int $deslocamento = 0,
        string $ordenacao = self::ORDEM_DATA_CRESCENTE,
    ): array {
        $parametros = array_filter([
            'territory_ids' => $codigoIbge,
            'published_since' => $desde,
            'published_until' => $ate,
            'sort_by' => $ordenacao,
            'size' => min($tamanho, self::TAMANHO_MAXIMO_PAGINA),
            'offset' => $deslocamento,
        ], static fn (mixed $valor): bool => $valor !== null);

        $json = $this->obterJson('/gazettes', $parametros);

        return [
            'total' => (int) ($json['total_gazettes'] ?? 0),
            'diarios' => $json['gazettes'] ?? [],
        ];
    }

    /**
     * Conta quantos diários o município tem, sem baixar a lista.
     */
    public function contarDiarios(string $codigoIbge, ?string $desde = null, ?string $ate = null): int
    {
        return $this->buscarDiarios($codigoIbge, $desde, $ate, tamanho: 1)['total'];
    }

    /**
     * Devolve o diário mais antigo e o mais recente do município.
     *
     * Custa duas requisições, em vez de percorrer o acervo inteiro.
     *
     * @return array{primeiro: array<string, mixed>|null, ultimo: array<string, mixed>|null}
     */
    public function obterExtremos(string $codigoIbge): array
    {
        $primeiro = $this->buscarDiarios($codigoIbge, tamanho: 1, ordenacao: self::ORDEM_DATA_CRESCENTE);
        $ultimo = $this->buscarDiarios($codigoIbge, tamanho: 1, ordenacao: self::ORDEM_DATA_DECRESCENTE);

        return [
            'primeiro' => $primeiro['diarios'][0] ?? null,
            'ultimo' => $ultimo['diarios'][0] ?? null,
        ];
    }

    /**
     * Percorre todos os diários do período, página por página.
     *
     * Devolve um Generator para não carregar milhares de registros na memória
     * de uma vez — Campinas sozinha tem mais de sete mil diários.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function percorrerDiarios(
        string $codigoIbge,
        ?string $desde = null,
        ?string $ate = null,
        int $tamanhoPagina = self::TAMANHO_MAXIMO_PAGINA,
        ?callable $aoAvancar = null,
    ): Generator {
        $deslocamento = 0;

        do {
            $pagina = $this->buscarDiarios(
                $codigoIbge,
                $desde,
                $ate,
                tamanho: $tamanhoPagina,
                deslocamento: $deslocamento,
                ordenacao: self::ORDEM_DATA_CRESCENTE,
            );

            foreach ($pagina['diarios'] as $diario) {
                yield $diario;
            }

            $recebidos = count($pagina['diarios']);
            $deslocamento += $recebidos;

            if ($aoAvancar !== null) {
                $aoAvancar($deslocamento, $pagina['total']);
            }

            // Para também quando a página vem vazia, senão um total inconsistente
            // com o que a API entrega viraria laço infinito.
        } while ($recebidos > 0 && $deslocamento < $pagina['total']);
    }

    private function semAcento(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>
     */
    private function obterJson(string $caminho, array $parametros = []): array
    {
        if ($this->cacheMinutos <= 0) {
            return $this->requisitar($caminho, $parametros)->throw()->json() ?? [];
        }

        $chave = 'qd:'.md5($caminho.'?'.http_build_query($parametros));

        return Cache::remember(
            $chave,
            now()->addMinutes($this->cacheMinutos),
            fn (): array => $this->requisitar($caminho, $parametros)->throw()->json() ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function requisitar(string $caminho, array $parametros = []): Response
    {
        $this->respeitarLimiteDeRequisicoes();

        return $this->http()->get($caminho, $parametros);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->urlBase)
            ->acceptJson()
            ->connectTimeout($this->timeoutConexao)
            ->timeout($this->timeout)
            ->retry(
                [1_000, 5_000, 15_000],
                when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }

    /**
     * Espera o tempo necessário para não passar do limite de requisições.
     *
     * O instante da última chamada fica no cache, e não em memória, para que o
     * limite continue valendo entre execuções seguidas de comandos diferentes.
     */
    private function respeitarLimiteDeRequisicoes(): void
    {
        if ($this->requisicoesPorMinuto <= 0) {
            return;
        }

        $intervalo = 60 / $this->requisicoesPorMinuto;
        $ultima = Cache::get(self::CHAVE_ULTIMA_REQUISICAO);

        if (is_numeric($ultima)) {
            $espera = ((float) $ultima + $intervalo) - microtime(true);

            if ($espera > 0) {
                usleep((int) round($espera * 1_000_000));
            }
        }

        Cache::put(self::CHAVE_ULTIMA_REQUISICAO, microtime(true), now()->addMinutes(5));
    }
}
