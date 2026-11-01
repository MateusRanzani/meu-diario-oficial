<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaAto extends Model
{
    protected $table = 'empresas_ato';

    protected $fillable = [
        'ato_id',
        'nome',
        'cnpj',
    ];

    public function ato(): BelongsTo
    {
        return $this->belongsTo(Ato::class);
    }
}
