<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Models\Municipio;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('diario:status {--ibge= : Limitar a um município}')]
#[Description('Mostra quantos diários há em cada status do pipeline')]
class StatusAcervo extends Command
{
    use ResolveOpcoesComuns;

    public function handle(): int
    {
        $municipios = Municipio::query()
            ->when($this->option('ibge'), fn ($consulta, $ibge) => $consulta->where('codigo_ibge', $ibge))
            ->orderBy('nome')
            ->get();

        if ($municipios->isEmpty()) {
            $this->warn('Nenhum município no banco ainda.');
            $this->line('Comece com: php artisan diario:coletar --desde=2026-10-01');

            return self::SUCCESS;
        }

        $contagens = Diario::query()
            ->selectRaw('municipio_id, status, count(*) as total, coalesce(sum(bytes), 0) as bytes')
            ->whereIn('municipio_id', $municipios->pluck('id'))
            ->groupBy('municipio_id', 'status')
            ->get()
            ->groupBy('municipio_id');

        foreach ($municipios as $municipio) {
            $this->exibirMunicipio($municipio, $contagens->get($municipio->id) ?? collect());
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, object>  $linhas
     */
    private function exibirMunicipio(Municipio $municipio, $linhas): void
    {
        $noBanco = (int) $linhas->sum('total');
        $bytes = (int) $linhas->sum('bytes');

        $this->newLine();
        $this->line("<options=bold>{$municipio->nome}/{$municipio->uf}</>  —  IBGE {$municipio->codigo_ibge}");

        if ($noBanco === 0) {
            $this->line('  Nenhum diário coletado ainda.');

            return;
        }

        $porStatus = $linhas->keyBy('status');
        $linhasTabela = [];

        foreach (StatusDiario::cases() as $status) {
            $total = (int) ($porStatus->get($status->value)->total ?? 0);

            if ($total === 0) {
                continue;
            }

            $linhasTabela[] = [
                $status->rotulo(),
                $this->numero($total),
                number_format($total / $noBanco * 100, 1, ',', '.').'%',
            ];
        }

        $this->table(['Status', 'Diários', 'Fatia'], $linhasTabela);

        $this->line('  No banco:  '.$this->numero($noBanco));

        if ($municipio->total_diarios_api !== null) {
            $faltam = $municipio->total_diarios_api - $noBanco;
            $this->line('  Na API:    '.$this->numero($municipio->total_diarios_api).
                ($faltam > 0 ? '  (faltam '.$this->numero($faltam).' por coletar)' : '  (acervo completo)'));
        }

        if ($bytes > 0) {
            $this->line('  Em disco:  '.$this->tamanhoLegivel($bytes));
        }

        $this->sugerirProximoPasso($municipio);
    }

    private function sugerirProximoPasso(Municipio $municipio): void
    {
        $pendentes = $municipio->diarios()->aguardandoDownload()->count();
        $comErro = $municipio->diarios()->where('status', StatusDiario::Erro)->count();

        if ($pendentes > 0) {
            $this->line('  <fg=cyan>Próximo:</>   php artisan diario:baixar --ibge='.$municipio->codigo_ibge
                .'   ('.$this->numero($pendentes).' aguardando)');
        }

        if ($comErro > 0) {
            $this->warn('  '.$this->numero($comErro).' diário(s) com erro. Veja a coluna erro_mensagem para o motivo.');
        }
    }
}
