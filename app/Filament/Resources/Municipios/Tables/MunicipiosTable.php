<?php

declare(strict_types=1);

namespace App\Filament\Resources\Municipios\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MunicipiosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')
                    ->label('Município')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('uf')
                    ->label('UF')
                    ->sortable()
                    ->badge(),

                TextColumn::make('total_diarios_api')
                    ->label('Diários (API)')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('data_primeiro_diario')
                    ->label('Primeiro diário')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('data_ultimo_diario')
                    ->label('Último diário')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('verificado_em')
                    ->label('Verificado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
