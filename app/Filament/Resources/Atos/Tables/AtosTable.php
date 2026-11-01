<?php

declare(strict_types=1);

namespace App\Filament\Resources\Atos\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AtosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('diario.data_publicacao')
                    ->label('Diário')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('diario.municipio.nome')
                    ->label('Município')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('numero_ato')
                    ->label('Número')
                    ->sortable(),

                TextColumn::make('orgao')
                    ->label('Órgão')
                    ->limit(40)
                    ->searchable(),

                TextColumn::make('objeto')
                    ->label('Objeto')
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('valor')
                    ->label('Valor (R$)')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('fonte_extracao')
                    ->label('Fonte')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo de ato')
                    ->options([
                        'decreto' => 'Decreto',
                        'portaria' => 'Portaria',
                        'lei' => 'Lei',
                        'resolucao' => 'Resolução',
                        'aviso_licitacao' => 'Aviso de Licitação',
                        'contrato' => 'Contrato',
                        'outro' => 'Outro',
                    ]),

                SelectFilter::make('fonte_extracao')
                    ->label('Fonte de extração')
                    ->options([
                        'regex' => 'Regex (automático)',
                        'llm' => 'IA',
                        'misto' => 'Misto',
                        'humano' => 'Revisão humana',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
