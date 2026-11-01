<?php

declare(strict_types=1);

namespace App\Filament\Resources\Diarios\Tables;

use App\Enums\StatusDiario;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DiariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('data_publicacao', 'desc')
            ->columns([
                TextColumn::make('municipio.nome')
                    ->label('Município')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('data_publicacao')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('edicao')
                    ->label('Edição')
                    ->sortable(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (StatusDiario $state): string => $state->rotulo())
                    ->colors([
                        'gray' => StatusDiario::Pendente->value,
                        'warning' => StatusDiario::SemTexto->value,
                        'success' => StatusDiario::Baixado->value,
                        'primary' => StatusDiario::Segmentado->value,
                        'success' => StatusDiario::Processado->value,
                        'danger' => StatusDiario::Erro->value,
                    ]),

                TextColumn::make('bytes')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? number_format($state / 1024, 0, ',', '.').' KB'
                        : '—')
                    ->sortable(),

                TextColumn::make('atos_count')
                    ->label('Atos')
                    ->counts('atos')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(
                        collect(StatusDiario::cases())
                            ->mapWithKeys(fn (StatusDiario $s): array => [$s->value => $s->rotulo()])
                            ->all()
                    ),

                SelectFilter::make('municipio')
                    ->label('Município')
                    ->relationship('municipio', 'nome'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
