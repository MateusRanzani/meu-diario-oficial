<?php

declare(strict_types=1);

namespace App\Filament\Resources\Atos\Pages;

use App\Filament\Resources\Atos\AtoResource;
use Filament\Resources\Pages\ListRecords;

class ListAtos extends ListRecords
{
    protected static string $resource = AtoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
