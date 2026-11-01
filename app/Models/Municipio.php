<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipio extends Model
{
    /** @use HasFactory<MunicipioFactory> */
    use HasFactory;

    protected $fillable = [
        'codigo_ibge',
        'nome',
        'uf',
        'nivel',
        'total_diarios_api',
        'data_primeiro_diario',
        'data_ultimo_diario',
        'verificado_em',
    ];

    /** @return HasMany<Diario, $this> */
    public function diarios(): HasMany
    {
        return $this->hasMany(Diario::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_primeiro_diario' => 'date',
            'data_ultimo_diario' => 'date',
            'verificado_em' => 'datetime',
            'total_diarios_api' => 'integer',
        ];
    }
}
