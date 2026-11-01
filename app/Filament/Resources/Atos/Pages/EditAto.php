<?php

namespace App\Filament\Resources\Atos\Pages;

use App\Filament\Resources\Atos\AtoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAto extends EditRecord
{
    protected static string $resource = AtoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
