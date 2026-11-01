<?php

declare(strict_types=1);

namespace App\Filament\Resources\Diarios\Pages;

use App\Filament\Resources\Diarios\DiarioResource;
use Filament\Resources\Pages\ListRecords;

class ListDiarios extends ListRecords
{
    protected static string $resource = DiarioResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
