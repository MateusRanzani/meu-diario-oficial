<?php

declare(strict_types=1);

namespace App\Filament\Resources\Diarios;

use App\Enums\StatusDiario;
use App\Filament\Resources\Diarios\Pages\ListDiarios;
use App\Filament\Resources\Diarios\Pages\ViewDiario;
use App\Filament\Resources\Diarios\Schemas\DiarioForm;
use App\Filament\Resources\Diarios\Tables\DiariosTable;
use App\Models\Diario;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DiarioResource extends Resource
{
    protected static ?string $model = Diario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Diários';

    protected static ?string $modelLabel = 'diário';

    protected static ?string $pluralModelLabel = 'diários';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return DiarioForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('municipio.nome')
                ->label('Município'),

            TextEntry::make('data_publicacao')
                ->label('Data de publicação')
                ->date('d/m/Y'),

            TextEntry::make('edicao')
                ->label('Edição'),

            TextEntry::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (StatusDiario $state): string => $state->rotulo()),

            TextEntry::make('bytes')
                ->label('Tamanho')
                ->formatStateUsing(fn (?int $state): string => $state
                    ? number_format($state / 1024 / 1024, 2, ',', '.').' MB'
                    : '—'),

            TextEntry::make('url_original')
                ->label('URL original')
                ->url(fn (Diario $record): string => $record->url_original ?? '#'),

            TextEntry::make('url_texto')
                ->label('URL do texto')
                ->url(fn (Diario $record): ?string => $record->url_texto),

            TextEntry::make('caminho_arquivo_local')
                ->label('Arquivo local'),

            TextEntry::make('hash_sha256')
                ->label('SHA-256')
                ->limit(20),

            TextEntry::make('scraped_at')
                ->label('Raspado em')
                ->dateTime('d/m/Y H:i'),

            TextEntry::make('erro_mensagem')
                ->label('Erro')
                ->visible(fn (Diario $record): bool => $record->status === StatusDiario::Erro),
        ]);
    }

    public static function table(Table $table): Table
    {
        return DiariosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiarios::route('/'),
            'view' => ViewDiario::route('/{record}'),
        ];
    }
}
