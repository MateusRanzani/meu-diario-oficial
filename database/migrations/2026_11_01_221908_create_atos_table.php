<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bloco_id')->nullable()->constrained('blocos')->nullOnDelete();
            $table->unsignedInteger('ordem');

            $table->longText('texto_original');

            // Campos extraídos (regex ou LLM)
            $table->string('tipo')->nullable();
            $table->string('numero_ato')->nullable();
            $table->date('data_ato')->nullable();
            $table->string('orgao')->nullable();
            $table->text('resumo')->nullable();
            $table->decimal('valor', 15, 2)->nullable();
            $table->text('objeto')->nullable();
            $table->decimal('confianca', 5, 4)->nullable();

            $table->json('campos_estruturados')->nullable();
            // fonte_extracao: regex | llm | misto | humano
            $table->string('fonte_extracao')->nullable();

            // Dados do LLM (preenchidos na Fase 4)
            $table->json('json_bruto')->nullable();
            $table->string('modelo_usado')->nullable();

            $table->string('status')->default('pendente');
            $table->timestamps();

            $table->index(['diario_id', 'ordem']);
            $table->index(['diario_id', 'tipo']);
            $table->index(['diario_id', 'status']);
        });

        // FTS5 para busca textual (SQLite)
        DB::statement("CREATE VIRTUAL TABLE IF NOT EXISTS atos_fts USING fts5(
            resumo,
            objeto,
            texto_original,
            content='atos',
            content_rowid='id'
        )");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS atos_fts');
        Schema::dropIfExists('atos');
    }
};
