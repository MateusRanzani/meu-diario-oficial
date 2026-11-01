<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ato extends Model
{
    protected $fillable = [
        'diario_id',
        'bloco_id',
        'ordem',
        'texto_original',
        'tipo',
        'numero_ato',
        'data_ato',
        'orgao',
        'resumo',
        'valor',
        'objeto',
        'confianca',
        'campos_estruturados',
        'fonte_extracao',
        'json_bruto',
        'modelo_usado',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data_ato' => 'date',
            'valor' => 'decimal:2',
            'confianca' => 'decimal:4',
            'campos_estruturados' => 'array',
            'json_bruto' => 'array',
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

    public function pessoas(): HasMany
    {
        return $this->hasMany(PessoaAto::class);
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(EmpresaAto::class);
    }
}
