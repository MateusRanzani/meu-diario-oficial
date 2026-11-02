<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Services\ExtratorCampos;
use App\Services\ParserMultas;
use App\Services\SegmentadorDiario;
use App\Services\TextoNormalizador;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('diario:segmentar
    {--ibge= : Código IBGE do município (padrão: QD_TERRITORY_ID do .env)}
    {--limite= : Limitar a N diários por execução}
    {--desde= : Segmentar a partir desta data, AAAA-MM-DD}
    {--ate= : Segmentar até esta data, AAAA-MM-DD}
    {--force : Re-segmentar diários que já foram segmentados anteriormente}')]
#[Description('Segmenta os diários baixados em blocos e extrai campos estruturados')]
class SegmentarDiarios extends Command
{
    use ResolveOpcoesComuns;

    public function handle(
        TextoNormalizador $normalizador,
        SegmentadorDiario $segmentador,
        ExtratorCampos $extrator,
        ParserMultas $parserMultas,
    ): int {
        ini_set('memory_limit', '512M');
        $codigoIbge = $this->codigoIbgeInformado();

        if ($codigoIbge === null) {
            return self::FAILURE;
        }

        $desde = $this->lerData('desde');
        $ate = $this->lerData('ate');

        if ($desde === false || $ate === false) {
            return self::FAILURE;
        }

        $statusPermitidos = $this->option('force')
            ? [StatusDiario::Baixado, StatusDiario::Segmentado]
            : [StatusDiario::Baixado];

        $consulta = Diario::query()
            ->whereRelation('municipio', 'codigo_ibge', $codigoIbge)
            ->whereIn('status', $statusPermitidos)
            ->whereNotNull('caminho_arquivo_local');

        if ($desde !== null) {
            $consulta->whereDate('data_publicacao', '>=', $desde);
        }

        if ($ate !== null) {
            $consulta->whereDate('data_publicacao', '<=', $ate);
        }

        if ($this->option('limite') !== null) {
            $consulta->limit((int) $this->option('limite'));
        }

        $total = $consulta->count();

        if ($total === 0) {
            $this->warn('Nenhum diário baixado para segmentar. Rode php artisan diario:baixar primeiro.');

            return self::SUCCESS;
        }

        $this->line("Segmentando {$this->numero($total)} diário(s)..");
        $barra = $this->output->createProgressBar($total);
        $barra->start();

        $processados = 0;
        $erros = 0;

        $consulta->each(function (Diario $diario) use (
            $normalizador,
            $segmentador,
            $extrator,
            $parserMultas,
            $barra,
            &$processados,
            &$erros,
        ): void {
            try {
                $this->segmentarDiario($diario, $normalizador, $segmentador, $extrator, $parserMultas);
                $processados++;
            } catch (\Throwable $e) {
                $erros++;
                $diario->update(['status' => StatusDiario::Erro, 'erro_mensagem' => $e->getMessage()]);
            }

            $barra->advance();
        });

        $barra->finish();
        $this->newLine(2);
        $this->line("Segmentados: {$this->numero($processados)}");

        if ($erros > 0) {
            $this->warn("Erros: {$this->numero($erros)}");
        }

        $this->newLine();
        $this->info('Segmentação concluída. Próximo passo: php artisan diario:status --ibge='.$codigoIbge);

        return self::SUCCESS;
    }

    private function segmentarDiario(
        Diario $diario,
        TextoNormalizador $normalizador,
        SegmentadorDiario $segmentador,
        ExtratorCampos $extrator,
        ParserMultas $parserMultas,
    ): void {
        $disco = Storage::disk('diarios');
        $caminho = (string) $diario->caminho_arquivo_local;

        if (! $disco->exists($caminho)) {
            throw new \RuntimeException("Arquivo não encontrado: {$caminho}");
        }

        $conteudo = $disco->get($caminho);

        if ($conteudo === null) {
            throw new \RuntimeException("Não foi possível ler o arquivo: {$caminho}");
        }

        $linhasOriginais = explode("\n", $conteudo);
        $linhasNormalizadas = $normalizador->normalizar($linhasOriginais);
        $blocos = $segmentador->segmentar($linhasNormalizadas);

        DB::transaction(function () use ($diario, $blocos, $extrator, $parserMultas): void {
            $diario->blocos()->delete();
            $diario->atos()->delete();
            $diario->multas()->delete();

            $ordemAto = 0;

            foreach ($blocos as $ordem => $dadosBloco) {
                $bloco = $diario->blocos()->create([
                    'ordem' => $ordem,
                    'tipo_bloco' => $dadosBloco['tipo'],
                    'linha_inicio' => $dadosBloco['linha_inicio'],
                    'linha_fim' => $dadosBloco['linha_fim'],
                    'byte_inicio' => $dadosBloco['byte_inicio'],
                    'byte_fim' => $dadosBloco['byte_fim'],
                    'texto_original' => $dadosBloco['texto'],
                    'texto_normalizado' => $dadosBloco['texto'],
                    'enviar_para_ia' => $dadosBloco['enviar_para_ia'],
                ]);

                $linhasNoBloco = $dadosBloco['linha_fim'] - $dadosBloco['linha_inicio'] + 1;

                if ($dadosBloco['tipo'] === 'tabela') {
                    $parserMultas->parsear($diario, $bloco, $dadosBloco['texto']);
                } elseif ($dadosBloco['tipo'] === 'ato' && $linhasNoBloco >= 5) {
                    $campos = $extrator->extrair($dadosBloco['texto']);

                    $diario->atos()->create([
                        'bloco_id' => $bloco->id,
                        'ordem' => $ordemAto++,
                        'texto_original' => $dadosBloco['texto'],
                        'tipo' => $this->inferirTipo($dadosBloco['texto']),
                        'numero_ato' => $campos['numero_ato'],
                        'orgao' => $campos['orgao'],
                        'campos_estruturados' => $campos['campos'],
                        'fonte_extracao' => 'regex',
                        'status' => 'pendente',
                    ]);
                }
            }

            $diario->update(['status' => StatusDiario::Segmentado]);
        });
    }

    private function inferirTipo(string $texto): ?string
    {
        $maiusculo = mb_strtoupper($texto);

        $tipos = [
            'decreto' => 'DECRETO',
            'portaria' => 'PORTARIA',
            'lei' => 'LEI Nº',
            'resolucao' => 'RESOLUÇÃO',
            'aviso_licitacao' => 'AVISO DE LICITAÇÃO',
            'contrato' => 'CONTRATO',
            'extrato' => 'EXTRATO',
            'nomeacao' => 'NOMEAÇÃO',
            'exoneracao' => 'EXONERAÇÃO',
            'autorizacao_despesa' => 'AUTORIZAÇÃO DE DESPESA',
        ];

        foreach ($tipos as $tipo => $palavra) {
            if (str_contains($maiusculo, $palavra)) {
                return $tipo;
            }
        }

        return 'outro';
    }
}
