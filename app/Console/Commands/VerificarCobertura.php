<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Services\QueridoDiarioClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('diario:cobertura
    {--ibge= : Código IBGE do município (padrão: QD_TERRITORY_ID do .env)}
    {--desde= : Analisar a partir desta data, AAAA-MM-DD}
    {--ate= : Analisar até esta data, AAAA-MM-DD}
    {--rapido : Só o resumo, sem percorrer o acervo inteiro}')]
#[Description('Diagnostica se um município tem diários utilizáveis antes de construir o pipeline')]
class VerificarCobertura extends Command
{
    use ResolveOpcoesComuns;

    /** Passando disso, o raspador do município provavelmente está parado. */
    private const DIAS_PARA_SUSPEITAR_DE_PARADA = 60;

    /** Acima desta fatia de diários sem TXT, o pipeline de texto fica inviável. */
    private const FATIA_SEM_TEXTO_PREOCUPANTE = 0.10;

    public function handle(QueridoDiarioClient $cliente): int
    {
        $codigoIbge = $this->codigoIbgeInformado();

        if ($codigoIbge === null) {
            return self::FAILURE;
        }

        $desde = $this->lerData('desde');
        $ate = $this->lerData('ate');

        if ($desde === false || $ate === false) {
            return self::FAILURE;
        }

        $municipio = $cliente->obterMunicipio($codigoIbge);

        if ($municipio === null) {
            $this->error("Não existe município com o código IBGE {$codigoIbge}.");

            return self::FAILURE;
        }

        $this->exibirCabecalho($municipio, $codigoIbge);

        $total = $cliente->contarDiarios($codigoIbge);

        if ($total === 0) {
            return $this->reprovarAcervoVazio();
        }

        $extremos = $cliente->obterExtremos($codigoIbge);
        $diasParado = $this->exibirExtremos($extremos, $total);

        if ($this->option('rapido')) {
            $this->newLine();
            $this->line('Rodado com --rapido: pulei a análise por ano, lacunas e TXT faltante.');

            return $this->concluir($diasParado, semTexto: 0, analisados: 0);
        }

        // Com recorte de datas, o acervo inteiro não serve de máximo para a barra
        // de progresso: ela precisa do total do período.
        $totalDoPeriodo = ($desde !== null || $ate !== null)
            ? $cliente->contarDiarios($codigoIbge, $desde, $ate)
            : $total;

        $analise = $this->percorrerAcervo($cliente, $codigoIbge, $desde, $ate, $totalDoPeriodo);

        if ($analise['analisados'] === 0) {
            $this->warn('Nenhum diário no período pedido. O acervo existe, mas o recorte está vazio.');

            return self::SUCCESS;
        }

        $this->exibirPorAno($analise);
        $this->exibirLacunas($analise['meses']);
        $this->exibirTotais($analise);

        return $this->concluir($diasParado, $analise['semTexto'], $analise['analisados']);
    }

    /**
     * @param  array<string, mixed>  $municipio
     */
    private function exibirCabecalho(array $municipio, string $codigoIbge): void
    {
        $nome = $municipio['territory_name'] ?? '?';
        $uf = $municipio['state_code'] ?? '?';
        $nivel = $municipio['level'] ?? '?';

        $this->newLine();
        $this->line("<options=bold>{$nome}/{$uf}</>  —  IBGE {$codigoIbge}, nível {$nivel}");
    }

    private function reprovarAcervoVazio(): int
    {
        $this->newLine();
        $this->error('ACERVO VAZIO: a API não tem nenhum diário deste município.');
        $this->line('O município está cadastrado, mas nunca foi raspado ou o raspador nunca funcionou.');
        $this->line('Não dá para construir o pipeline em cima disso. Escolha outro município.');

        return self::FAILURE;
    }

    /**
     * @param  array{primeiro: array<string, mixed>|null, ultimo: array<string, mixed>|null}  $extremos
     * @return int Dias desde o diário mais recente.
     */
    private function exibirExtremos(array $extremos, int $total): int
    {
        $primeiro = $extremos['primeiro']['date'] ?? null;
        $ultimo = $extremos['ultimo']['date'] ?? null;

        $this->line('Acervo total:  '.$this->numero($total).' diários');
        $this->line('Primeiro:      '.($primeiro ?? '?'));

        if ($ultimo === null) {
            $this->line('Último:        ?');

            return 0;
        }

        $diasParado = (int) CarbonImmutable::parse($ultimo)->diffInDays(CarbonImmutable::now());
        $idade = $diasParado === 0 ? 'hoje' : "há {$diasParado} dia".($diasParado > 1 ? 's' : '');

        $this->line("Último:        {$ultimo}  ({$idade})");

        if ($diasParado > self::DIAS_PARA_SUSPEITAR_DE_PARADA) {
            $this->newLine();
            $this->warn("O diário mais recente tem {$diasParado} dias. O raspador parece parado.");
        }

        return $diasParado;
    }

    /**
     * @return array{analisados: int, semTexto: int, extras: int, meses: array<string, int>}
     */
    private function percorrerAcervo(
        QueridoDiarioClient $cliente,
        string $codigoIbge,
        ?string $desde,
        ?string $ate,
        int $total,
    ): array {
        $this->newLine();
        $this->line('Percorrendo o acervo para medir lacunas e TXT faltante...');

        $barra = $this->output->createProgressBar($total);
        $barra->start();

        $analisados = 0;
        $semTexto = 0;
        $extras = 0;
        $meses = [];

        foreach ($cliente->percorrerDiarios($codigoIbge, $desde, $ate, aoAvancar: function (int $lidos) use ($barra): void {
            $barra->setProgress(min($lidos, $barra->getMaxSteps()));
        }) as $diario) {
            $analisados++;

            if (empty($diario['txt_url'])) {
                $semTexto++;
            }

            if (($diario['is_extra_edition'] ?? false) === true) {
                $extras++;
            }

            $data = $diario['date'] ?? null;

            if (is_string($data) && $data !== '') {
                $mes = substr($data, 0, 7);
                $meses[$mes] = ($meses[$mes] ?? 0) + 1;
            }
        }

        $barra->finish();
        $this->newLine(2);

        ksort($meses);

        return compact('analisados', 'semTexto', 'extras', 'meses');
    }

    /**
     * @param  array{analisados: int, semTexto: int, extras: int, meses: array<string, int>}  $analise
     */
    private function exibirPorAno(array $analise): void
    {
        $anos = [];

        foreach ($analise['meses'] as $mes => $quantidade) {
            $ano = substr($mes, 0, 4);
            $anos[$ano]['diarios'] = ($anos[$ano]['diarios'] ?? 0) + $quantidade;
            $anos[$ano]['meses'] = ($anos[$ano]['meses'] ?? 0) + 1;
        }

        $this->table(
            ['Ano', 'Diários', 'Meses com diário'],
            array_map(
                fn (string $ano, array $dados): array => [
                    $ano,
                    $this->numero($dados['diarios']),
                    $dados['meses'].'/12',
                ],
                array_keys($anos),
                $anos,
            ),
        );
    }

    /**
     * Lista os meses sem nenhum diário dentro do intervalo já coberto.
     *
     * Só conta como lacuna o mês que está entre o primeiro e o último diário:
     * antes do primeiro não é buraco, é só o acervo ainda não ter começado.
     *
     * @param  array<string, int>  $meses
     */
    private function exibirLacunas(array $meses): void
    {
        if ($meses === []) {
            return;
        }

        $chaves = array_keys($meses);
        $cursor = CarbonImmutable::parse(reset($chaves).'-01');
        $fim = CarbonImmutable::parse(end($chaves).'-01');

        $lacunas = [];

        while ($cursor->lessThanOrEqualTo($fim)) {
            $mes = $cursor->format('Y-m');

            if (! isset($meses[$mes])) {
                $lacunas[] = $mes;
            }

            $cursor = $cursor->addMonth();
        }

        if ($lacunas === []) {
            $this->line('<fg=green>Sem lacunas:</> todo mês do período coberto tem pelo menos um diário.');

            return;
        }

        $this->warn(count($lacunas).' mês(es) sem nenhum diário dentro do período coberto:');
        $this->line('  '.implode(', ', $lacunas));
    }

    /**
     * @param  array{analisados: int, semTexto: int, extras: int, meses: array<string, int>}  $analise
     */
    private function exibirTotais(array $analise): void
    {
        $analisados = $analise['analisados'];
        $semTexto = $analise['semTexto'];
        $percentual = $analisados > 0 ? ($semTexto / $analisados) * 100 : 0.0;

        $this->newLine();
        $this->line('Analisados:     '.$this->numero($analisados));
        $this->line('Edições extras: '.$this->numero($analise['extras']));
        $this->line('Sem TXT:        '.$this->numero($semTexto).' ('.number_format($percentual, 1, ',', '.').'%)');

        if ($semTexto > 0) {
            $this->line('  Diário sem txt_url não tem versão em texto: o pipeline não consegue lê-lo.');
        }
    }

    private function concluir(int $diasParado, int $semTexto, int $analisados): int
    {
        $fatiaSemTexto = $analisados > 0 ? $semTexto / $analisados : 0.0;
        $parado = $diasParado > self::DIAS_PARA_SUSPEITAR_DE_PARADA;
        $poucoTexto = $fatiaSemTexto > self::FATIA_SEM_TEXTO_PREOCUPANTE;

        $this->newLine();

        if ($parado && $poucoTexto) {
            $this->error('VEREDITO: acervo parado e com muito diário sem texto. Procure outro município.');

            return self::FAILURE;
        }

        if ($parado) {
            $this->warn('VEREDITO: o acervo existe, mas está desatualizado.');
            $this->line('Serve para desenvolver em cima de dado histórico; não serve para acompanhamento diário.');

            return self::SUCCESS;
        }

        if ($poucoTexto) {
            $this->warn('VEREDITO: acervo ativo, mas boa parte dos diários não tem versão em texto.');
            $this->line('O pipeline vai conseguir ler só uma parte do que existe.');

            return self::SUCCESS;
        }

        $this->info('VEREDITO: município utilizável. Pode seguir para a coleta.');

        return self::SUCCESS;
    }
}
