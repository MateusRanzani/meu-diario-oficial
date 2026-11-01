<?php

declare(strict_types=1);

namespace App\Filament\Resources\Diarios\Pages;

use App\Filament\Resources\Diarios\DiarioResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDiario extends ViewRecord
{
    protected static string $resource = DiarioResource::class;
}
