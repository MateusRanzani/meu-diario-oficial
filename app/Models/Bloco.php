<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bloco extends Model
{
    protected $fillable = [
        'diario_id',
        'ordem',
        'tipo_bloco',
        'linha_inicio',
        'linha_fim',
        'byte_inicio',
        'byte_fim',
        'texto_original',
        'texto_normalizado',
        'enviar_para_ia',
    ];

    protected function casts(): array
    {
        return [
            'enviar_para_ia' => 'boolean',
            'linha_inicio' => 'integer',
            'linha_fim' => 'integer',
            'byte_inicio' => 'integer',
            'byte_fim' => 'integer',
        ];
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(Diario::class);
    }

    public function atos(): HasMany
    {
        return $this->hasMany(Ato::class);
    }

    public function multas(): HasMany
    {
        return $this->hasMany(Multa::class);
    }
}
