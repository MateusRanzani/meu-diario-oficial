<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PessoaAto extends Model
{
    protected $table = 'pessoas_ato';

    protected $fillable = [
        'ato_id',
        'nome',
        'cargo',
        'matricula',
    ];

    public function ato(): BelongsTo
    {
        return $this->belongsTo(Ato::class);
    }
}
