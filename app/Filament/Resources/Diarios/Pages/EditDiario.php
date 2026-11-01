<?php

namespace App\Filament\Resources\Diarios\Pages;

use App\Filament\Resources\Diarios\DiarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDiario extends EditRecord
{
    protected static string $resource = DiarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
