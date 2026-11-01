<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\StatusDiario;
use App\Models\Ato;
use App\Models\Diario;
use App\Models\Municipio;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AcervoStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalDiarios = Diario::count();
        $baixados = Diario::where('status', StatusDiario::Baixado)->count();
        $segmentados = Diario::where('status', StatusDiario::Segmentado)->count();
        $processados = Diario::where('status', StatusDiario::Processado)->count();
        $totalAtos = Ato::count();
        $municipios = Municipio::count();

        $bytesTotais = Diario::whereNotNull('bytes')->sum('bytes');
        $mb = $bytesTotais > 0 ? number_format($bytesTotais / 1024 / 1024, 1, ',', '.') : '0';

        return [
            Stat::make('Diários coletados', number_format($totalDiarios, 0, ',', '.'))
                ->description("{$municipios} município(s) monitorado(s)")
                ->color('primary'),

            Stat::make('Baixados', number_format($baixados, 0, ',', '.'))
                ->description("{$mb} MB em disco")
                ->color('success'),

            Stat::make('Atos extraídos', number_format($totalAtos, 0, ',', '.'))
                ->description($totalAtos > 0 ? 'Via regex, sem IA' : 'Rode diario:segmentar para extrair')
                ->color($totalAtos > 0 ? 'success' : 'gray'),
        ];
    }
}
