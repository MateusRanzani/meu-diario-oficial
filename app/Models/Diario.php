<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusDiario;
use Database\Factories\DiarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Diario extends Model
{
    /** @use HasFactory<DiarioFactory> */
    use HasFactory;

    protected $fillable = [
        'municipio_id',
        'data_publicacao',
        'edicao',
        'edicao_extra',
        'url_original',
        'url_texto',
        'caminho_arquivo_local',
        'bytes',
        'hash_sha256',
        'scraped_at',
        'status',
        'erro_mensagem',
    ];

    /** @return BelongsTo<Municipio, $this> */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * Diários que ainda têm texto para baixar.
     *
     * @param  Builder<Diario>  $query
     */
    public function scopeAguardandoDownload(Builder $query): void
    {
        $query->where('status', StatusDiario::Pendente)->whereNotNull('url_texto');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_publicacao' => 'date',
            'edicao_extra' => 'boolean',
            'scraped_at' => 'datetime',
            'bytes' => 'integer',
            'status' => StatusDiario::class,
        ];
    }
}
