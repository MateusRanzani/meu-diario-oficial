<?php

declare(strict_types=1);

namespace App\Filament\Resources\Atos;

use App\Filament\Resources\Atos\Pages\ListAtos;
use App\Filament\Resources\Atos\Pages\ViewAto;
use App\Filament\Resources\Atos\Schemas\AtoForm;
use App\Filament\Resources\Atos\Tables\AtosTable;
use App\Models\Ato;
use BackedEnum;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AtoResource extends Resource
{
    protected static ?string $model = Ato::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Atos';

    protected static ?string $modelLabel = 'ato';

    protected static ?string $pluralModelLabel = 'atos';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AtoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('diario.data_publicacao')
                ->label('Diário de')
                ->date('d/m/Y'),

            TextEntry::make('diario.municipio.nome')
                ->label('Município'),

            TextEntry::make('tipo')
                ->label('Tipo')
                ->badge(),

            TextEntry::make('numero_ato')
                ->label('Número'),

            TextEntry::make('orgao')
                ->label('Órgão'),

            TextEntry::make('data_ato')
                ->label('Data do ato')
                ->date('d/m/Y'),

            TextEntry::make('valor')
                ->label('Valor (R$)')
                ->money('BRL'),

            TextEntry::make('objeto')
                ->label('Objeto')
                ->columnSpanFull(),

            TextEntry::make('resumo')
                ->label('Resumo')
                ->columnSpanFull(),

            KeyValueEntry::make('campos_estruturados')
                ->label('Campos extraídos (regex)')
                ->columnSpanFull(),

            TextEntry::make('fonte_extracao')
                ->label('Fonte da extração')
                ->badge(),

            TextEntry::make('texto_original')
                ->label('Texto original')
                ->columnSpanFull()
                ->prose(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AtosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAtos::route('/'),
            'view' => ViewAto::route('/{record}'),
        ];
    }
}
