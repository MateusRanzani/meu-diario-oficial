<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Models\Diario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('diario:conferir-segmentacao
    {diario : ID do diário, ou a data da publicação em AAAA-MM-DD}
    {--ibge= : Município, quando o diário for informado por data}')]
#[Description('Exibe as fronteiras dos blocos de um diário segmentado para conferência visual')]
class ConferirSegmentacao extends Command
{
    use ResolveOpcoesComuns;

    public function handle(): int
    {
        $referencia = (string) $this->argument('diario');
        $consulta = Diario::query()->with(['blocos', 'municipio']);

        if (ctype_digit($referencia)) {
            $diario = $consulta->find((int) $referencia);
        } else {
            $codigoIbge = $this->codigoIbgeInformado();

            if ($codigoIbge === null) {
                return self::FAILURE;
            }

            $diario = $consulta
                ->whereRelation('municipio', 'codigo_ibge', $codigoIbge)
                ->whereDate('data_publicacao', $referencia)
                ->first();
        }

        if ($diario === null) {
            $this->error('Diário não encontrado.');

            return self::FAILURE;
        }

        if ($diario->blocos->isEmpty()) {
            $this->warn('Este diário ainda não foi segmentado. Rode: php artisan diario:segmentar --ibge='.$diario->municipio->codigo_ibge);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<options=bold>Diário #{$diario->id}</> — {$diario->municipio->nome}/{$diario->municipio->uf}, "
            .$diario->data_publicacao->format('d/m/Y'));
        $this->line('{$this->numero($diario->blocos->count())} blocos');
        $this->newLine();

        $linhas = [];
        $totalAtos = 0;
        $totalTabelas = 0;

        foreach ($diario->blocos as $bloco) {
            if ($bloco->tipo_bloco === 'ato') {
                $totalAtos++;
            }

            if ($bloco->tipo_bloco === 'tabela') {
                $totalTabelas++;
            }

            $preview = mb_strimwidth(preg_replace('/\s+/', ' ', trim($bloco->texto_original)) ?? '', 0, 60, '…');

            $linhas[] = [
                "#{$bloco->ordem}",
                $bloco->tipo_bloco,
                "L{$bloco->linha_inicio}–{$bloco->linha_fim}",
                $bloco->enviar_para_ia ? '<fg=green>IA</>' : '<fg=gray>skip</>',
                $preview,
            ];
        }

        $this->table(['#', 'Tipo', 'Linhas', 'IA?', 'Preview'], $linhas);
        $this->newLine();
        $this->line("Atos:    {$this->numero($totalAtos)}");
        $this->line("Tabelas: {$this->numero($totalTabelas)} (parseadas por regex, sem IA)");

        return self::SUCCESS;
    }
}
