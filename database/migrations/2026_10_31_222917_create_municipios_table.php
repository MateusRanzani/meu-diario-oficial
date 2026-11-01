<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->id();

            // Guardado como texto porque a API devolve string ("3509502") e
            // porque código IBGE é identificador, não número para somar.
            $table->string('codigo_ibge')->unique();
            $table->string('nome');
            $table->string('uf', 2);
            $table->string('nivel')->nullable();

            // Retrato da cobertura na última vez que consultamos a API, para
            // comparar depois com o que temos de fato no banco.
            $table->unsignedInteger('total_diarios_api')->nullable();
            $table->date('data_primeiro_diario')->nullable();
            $table->date('data_ultimo_diario')->nullable();
            $table->timestamp('verificado_em')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
