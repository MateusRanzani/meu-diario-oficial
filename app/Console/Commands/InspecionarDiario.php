<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Models\Diario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('diario:inspecionar
    {diario : ID do diário, ou a data da publicação em AAAA-MM-DD}
    {--ibge= : Município, quando o diário for informado por data}')]
#[Description('Relatório do texto de um diário baixado: composição, ruído e campos aproveitáveis')]
class InspecionarDiario extends Command
{
    use ResolveOpcoesComuns;

    /**
     * Cabeçalhos clássicos de ato.
     *
     * Medir quantos aparecem é o ponto principal deste comando: num diário real
     * de Campinas eles cobriam 27 atos em 17.874 linhas, menos de 1% do
     * conteúdo. Serve para decidir quanto a segmentação por regex vai render
     * antes de escrevê-la.
     */
    private const CABECALHOS_CLASSICOS = [
        'DECRETO Nº', 'PORTARIA', 'LEI Nº', 'RESOLUÇÃO', 'AVISO DE LICITAÇÃO',
        'TERMO ADITIVO', 'ATA DE REGISTRO DE PREÇO', 'DISPENSA', 'EXTRATO', 'CONTRATO Nº',
    ];

    public function handle(): int
    {
        $diario = $this->encontrarDiario();

        if ($diario === null) {
            return self::FAILURE;
        }

        $disco = Storage::disk('diarios');
        $caminho = (string) $diario->caminho_arquivo_local;

        if ($caminho === '' || ! $disco->exists($caminho)) {
            $this->error('Este diário ainda não foi baixado.');
            $this->line('Rode: php artisan diario:baixar --ibge='.$diario->municipio->codigo_ibge);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<options=bold>Diário #{$diario->id}</>  —  {$diario->municipio->nome}/{$diario->municipio->uf}, "
            .$diario->data_publicacao->format('d/m/Y').', edição '.($diario->edicao ?? '—'));
        $this->line('Arquivo: '.$caminho);

        $analise = $this->analisar($disco->path($caminho));

        $this->exibirComposicao($analise);
        $this->exibirRuido($analise);
        $this->exibirCamposAproveitaveis($analise);
        $this->exibirCabecalhosClassicos($analise);

        return self::SUCCESS;
    }

    private function encontrarDiario(): ?Diario
    {
        $referencia = (string) $this->argument('diario');

        $consulta = Diario::query()->with('municipio');

        if (ctype_digit($referencia)) {
            $diario = $consulta->find((int) $referencia);

            if ($diario === null) {
                $this->error("Não existe diário com o ID {$referencia}.");
            }

            return $diario;
        }

        $codigoIbge = $this->codigoIbgeInformado();

        if ($codigoIbge === null) {
            return null;
        }

        $encontrados = $consulta
            ->whereRelation('municipio', 'codigo_ibge', $codigoIbge)
            ->whereDate('data_publicacao', $referencia)
            ->orderBy('id')
            ->get();

        if ($encontrados->isEmpty()) {
            $this->error("Nenhum diário em {$referencia} para o município {$codigoIbge}.");

            return null;
        }

        if ($encontrados->count() > 1) {
            $this->warn('Há '.$encontrados->count()." diários em {$referencia}. Usando o primeiro; os IDs são: "
                .$encontrados->pluck('id')->implode(', '));
        }

        return $encontrados->first();
    }

    /**
     * Lê o arquivo linha a linha, sem carregá-lo inteiro na memória.
     *
     * @return array<string, mixed>
     */
    private function analisar(string $caminhoAbsoluto): array
    {
        $categorias = ['vazia' => 0, 'tabular' => 0, 'assinatura' => 0, 'chave_valor' => 0, 'caixa_alta' => 0, 'texto' => 0];
        $bytesPorCategoria = $categorias;

        $linhas = 0;
        $bytes = 0;
        $hifenizadas = 0;
        $espacoDuplo = 0;
        $repetidas = [];
        $chaves = [];
        $cabecalhos = array_fill_keys(self::CABECALHOS_CLASSICOS, 0);

        $arquivo = fopen($caminhoAbsoluto, 'rb');

        while (($linha = fgets($arquivo)) !== false) {
            $linhas++;
            $tamanho = strlen($linha);
            $bytes += $tamanho;

            $limpa = trim($linha);
            $categoria = $this->classificar($limpa);

            $categorias[$categoria]++;
            $bytesPorCategoria[$categoria] += $tamanho;

            if ($limpa === '') {
                continue;
            }

            if (str_ends_with($limpa, '-')) {
                $hifenizadas++;
            }

            if (str_contains($limpa, '  ')) {
                $espacoDuplo++;
            }

            $repetidas[$limpa] = ($repetidas[$limpa] ?? 0) + 1;

            if ($categoria === 'chave_valor' && preg_match('/^([^:]{2,40}):(?!\/\/)/u', $limpa, $partes) === 1) {
                $chave = trim($partes[1]);
                $chaves[$chave] = ($chaves[$chave] ?? 0) + 1;
            }

            $maiuscula = mb_strtoupper($limpa);

            foreach (self::CABECALHOS_CLASSICOS as $cabecalho) {
                if (str_contains($maiuscula, $cabecalho)) {
                    $cabecalhos[$cabecalho]++;
                }
            }
        }

        fclose($arquivo);

        arsort($repetidas);
        arsort($chaves);

        return compact('linhas', 'bytes', 'categorias', 'bytesPorCategoria', 'hifenizadas', 'espacoDuplo', 'repetidas', 'chaves', 'cabecalhos');
    }

    private function classificar(string $linha): string
    {
        if ($linha === '') {
            return 'vazia';
        }

        // Duas tabulações ou mais indicam coluna, não prosa. É o formato das
        // multas de trânsito, que não devem ir para a IA.
        if (substr_count($linha, "\t") >= 2) {
            return 'tabular';
        }

        if (preg_match('/^.{3,40},\s+\d{1,2}\s+de\s+\p{L}+\s+de\s+\d{4}\.?$/u', $linha) === 1) {
            return 'assinatura';
        }

        // O (?!\/\/) evita que "https://..." seja lido como um campo cuja
        // chave é "https".
        if (preg_match('/^[^:]{2,40}:(?!\/\/)\s*\S/u', $linha) === 1) {
            return 'chave_valor';
        }

        if (preg_match('/\p{L}/u', $linha) === 1 && mb_strtoupper($linha) === $linha) {
            return 'caixa_alta';
        }

        return 'texto';
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function exibirComposicao(array $analise): void
    {
        $rotulos = [
            'tabular' => 'Tabular (2+ tabs)',
            'texto' => 'Texto corrido',
            'vazia' => 'Vazia',
            'caixa_alta' => 'CAIXA ALTA',
            'chave_valor' => 'Chave: valor',
            'assinatura' => 'Cidade, data',
        ];

        $this->newLine();
        $this->line('Bytes:  '.$this->tamanhoLegivel($analise['bytes']));
        $this->line('Linhas: '.$this->numero($analise['linhas']));
        $this->newLine();

        $linhasTabela = [];

        foreach ($rotulos as $chave => $rotulo) {
            $quantidade = $analise['categorias'][$chave];
            $linhasTabela[] = [
                $rotulo,
                $this->numero($quantidade),
                $this->percentual($quantidade, $analise['linhas']),
                $this->percentual($analise['bytesPorCategoria'][$chave], $analise['bytes']),
            ];
        }

        $this->table(['Tipo de linha', 'Linhas', '% linhas', '% bytes'], $linhasTabela);

        $fatiaTabular = $analise['bytes'] > 0 ? $analise['bytesPorCategoria']['tabular'] / $analise['bytes'] : 0;

        if ($fatiaTabular > 0.2) {
            $this->line('<fg=green>'.$this->percentual($analise['bytesPorCategoria']['tabular'], $analise['bytes'])
                .' do arquivo é tabela.</> Esse volume sai por parsing, sem custar nada de LLM.');
        }
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function exibirRuido(array $analise): void
    {
        $this->newLine();
        $this->line('<options=bold>Ruído da extração de PDF</>');
        $this->line('  Linhas terminando em hífen (palavra quebrada): '.$this->numero($analise['hifenizadas']));
        $this->line('  Linhas com espaço duplo (justificação):        '.$this->numero($analise['espacoDuplo']));

        $repetidas = array_filter($analise['repetidas'], static fn (int $n): bool => $n >= 5);

        if ($repetidas === []) {
            return;
        }

        $this->newLine();
        $this->line('  Linhas mais repetidas (candidatas a cabeçalho e rodapé de página):');

        foreach (array_slice($repetidas, 0, 5, true) as $linha => $vezes) {
            $this->line(sprintf('    %4dx  %s', $vezes, mb_strimwidth($linha, 0, 68, '...')));
        }
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function exibirCamposAproveitaveis(array $analise): void
    {
        $chaves = array_filter($analise['chaves'], static fn (int $n): bool => $n >= 3);

        $this->newLine();
        $this->line('<options=bold>Campos "Chave: valor" mais frequentes</>');

        if ($chaves === []) {
            $this->line('  Nenhum campo recorrente. A extração por regex vai render pouco neste diário.');

            return;
        }

        foreach (array_slice($chaves, 0, 12, true) as $chave => $vezes) {
            $this->line(sprintf('  %4dx  %s', $vezes, mb_strimwidth($chave, 0, 50, '...')));
        }

        $this->newLine();
        $this->line('  Estes campos já vêm nomeados: dão para extrair sem IA.');
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function exibirCabecalhosClassicos(array $analise): void
    {
        $encontrados = array_filter($analise['cabecalhos']);
        $total = array_sum($encontrados);

        $this->newLine();
        $this->line('<options=bold>Cabeçalhos clássicos de ato</>');

        if ($encontrados === []) {
            $this->line('  Nenhum encontrado.');
        } else {
            arsort($encontrados);

            foreach ($encontrados as $cabecalho => $vezes) {
                $this->line(sprintf('  %4dx  %s', $vezes, $cabecalho));
            }
        }

        $this->newLine();
        $this->line('  Total: '.$this->numero($total).' ocorrências em '.$this->numero($analise['linhas']).' linhas ('
            .$this->percentual($total, $analise['linhas']).').');
        $this->line('  Se a fatia for baixa, segmentar só por estes cabeçalhos perde quase todo o diário:');
        $this->line('  o grosso é ato administrativo, que se reconhece por título em CAIXA ALTA e assinatura.');
    }

    private function percentual(int $parte, int $total): string
    {
        return $total > 0
            ? number_format($parte / $total * 100, 1, ',', '.').'%'
            : '0,0%';
    }
}
