<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diario_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordem');

            $table->string('tipo_bloco');
            // Valores: cabecalho_pagina | titulo_secao | ato | tabela | assinatura | ignorado

            $table->unsignedInteger('linha_inicio');
            $table->unsignedInteger('linha_fim');
            $table->unsignedBigInteger('byte_inicio');
            $table->unsignedBigInteger('byte_fim');

            $table->longText('texto_original');
            $table->longText('texto_normalizado')->nullable();

            $table->boolean('enviar_para_ia')->default(false);

            $table->timestamps();

            $table->index(['diario_id', 'ordem']);
            $table->index(['diario_id', 'tipo_bloco']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocos');
    }
};
