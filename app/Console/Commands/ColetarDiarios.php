<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Models\Municipio;
use App\Services\QueridoDiarioClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('diario:coletar
    {--ibge= : Código IBGE do município (padrão: QD_TERRITORY_ID do .env)}
    {--desde= : Coletar a partir desta data, AAAA-MM-DD}
    {--ate= : Coletar até esta data, AAAA-MM-DD}')]
#[Description('Registra no banco os metadados dos diários de um município')]
class ColetarDiarios extends Command
{
    use ResolveOpcoesComuns;

    /**
     * Quantos diários gravar por transação.
     *
     * Sem agrupar, cada insert vira uma transação com sincronização em disco, e
     * os 7.697 diários de Campinas levariam minutos. Agrupado, leva segundos.
     */
    private const TAMANHO_DO_LOTE = 500;

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

        $municipio = $this->registrarMunicipio($cliente, $codigoIbge);

        if ($municipio === null) {
            return self::FAILURE;
        }

        $total = $cliente->contarDiarios($codigoIbge, $desde, $ate);

        if ($total === 0) {
            $this->warn('Nenhum diário para coletar nesse período.');

            return self::SUCCESS;
        }

        $this->line('Coletando '.$this->numero($total).' diários...');

        $contagem = $this->coletar($cliente, $municipio, $codigoIbge, $desde, $ate, $total);

        $this->newLine();
        $this->line('Novos:          '.$this->numero($contagem['novos']));
        $this->line('Já registrados: '.$this->numero($contagem['existentes']));

        if ($contagem['semTexto'] > 0) {
            $this->warn($this->numero($contagem['semTexto']).' diário(s) sem versão em texto: só existe o PDF.');
        }

        $this->newLine();
        $this->info('Coleta concluída. Próximo passo: php artisan diario:baixar');

        return self::SUCCESS;
    }

    private function registrarMunicipio(QueridoDiarioClient $cliente, string $codigoIbge): ?Municipio
    {
        $dados = $cliente->obterMunicipio($codigoIbge);

        if ($dados === null) {
            $this->error("Não existe município com o código IBGE {$codigoIbge}.");

            return null;
        }

        $extremos = $cliente->obterExtremos($codigoIbge);

        $municipio = Municipio::updateOrCreate(
            ['codigo_ibge' => $codigoIbge],
            [
                'nome' => $dados['territory_name'] ?? '?',
                'uf' => $dados['state_code'] ?? '??',
                'nivel' => $dados['level'] ?? null,
                'total_diarios_api' => $cliente->contarDiarios($codigoIbge),
                'data_primeiro_diario' => $extremos['primeiro']['date'] ?? null,
                'data_ultimo_diario' => $extremos['ultimo']['date'] ?? null,
                'verificado_em' => now(),
            ],
        );

        $this->newLine();
        $this->line("<options=bold>{$municipio->nome}/{$municipio->uf}</>  —  IBGE {$codigoIbge}");

        return $municipio;
    }

    /**
     * @return array{novos: int, existentes: int, semTexto: int}
     */
    private function coletar(
        QueridoDiarioClient $cliente,
        Municipio $municipio,
        string $codigoIbge,
        ?string $desde,
        ?string $ate,
        int $total,
    ): array {
        $barra = $this->output->createProgressBar($total);
        $barra->start();

        $contagem = ['novos' => 0, 'existentes' => 0, 'semTexto' => 0];
        $lote = [];

        $gravar = function () use (&$lote, &$contagem, $municipio, $barra): void {
            if ($lote === []) {
                return;
            }

            foreach ($this->gravarLote($municipio, $lote) as $chave => $quantidade) {
                $contagem[$chave] += $quantidade;
            }

            $barra->advance(count($lote));
            $lote = [];
        };

        foreach ($cliente->percorrerDiarios($codigoIbge, $desde, $ate) as $dados) {
            $lote[] = $dados;

            if (count($lote) >= self::TAMANHO_DO_LOTE) {
                $gravar();
            }
        }

        $gravar();

        $barra->finish();
        $this->newLine(2);

        return $contagem;
    }

    /**
     * @param  array<int, array<string, mixed>>  $diarios
     * @return array{novos: int, existentes: int, semTexto: int}
     */
    private function gravarLote(Municipio $municipio, array $diarios): array
    {
        return DB::transaction(function () use ($municipio, $diarios): array {
            $novos = 0;
            $existentes = 0;
            $semTexto = 0;

            foreach ($diarios as $dados) {
                $url = $dados['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $diario = Diario::firstOrNew(['url_original' => $url]);
                $temTexto = ! empty($dados['txt_url']);

                if ($diario->exists) {
                    $existentes++;
                } else {
                    $novos++;
                    // O status só é definido na criação. Reescrevê-lo aqui jogaria
                    // fora o progresso de um diário já baixado ou já segmentado.
                    $diario->status = $temTexto ? StatusDiario::Pendente : StatusDiario::SemTexto;
                }

                if (! $temTexto) {
                    $semTexto++;
                }

                $diario->fill([
                    'municipio_id' => $municipio->id,
                    'data_publicacao' => $dados['date'] ?? null,
                    'edicao' => $dados['edition'] ?? null,
                    'edicao_extra' => (bool) ($dados['is_extra_edition'] ?? false),
                    'url_texto' => $dados['txt_url'] ?? null,
                    'scraped_at' => $dados['scraped_at'] ?? null,
                ])->save();
            }

            return ['novos' => $novos, 'existentes' => $existentes, 'semTexto' => $semTexto];
        });
    }
}
