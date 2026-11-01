<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Multa extends Model
{
    protected $fillable = [
        'diario_id',
        'bloco_id',
        'placa',
        'auto_infracao',
        'codigo',
        'data_infracao',
        'valor',
    ];

    protected function casts(): array
    {
        return [
            'data_infracao' => 'date',
            'valor' => 'decimal:2',
        ];
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(Diario::class);
    }

    public function bloco(): BelongsTo
    {
        return $this->belongsTo(Bloco::class);
    }
}
