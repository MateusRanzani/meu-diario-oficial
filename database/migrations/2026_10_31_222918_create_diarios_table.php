<?php

declare(strict_types=1);

use App\Enums\StatusDiario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipio_id')->constrained('municipios')->cascadeOnDelete();

            $table->date('data_publicacao');
            $table->string('edicao')->nullable();
            $table->boolean('edicao_extra')->default(false);

            /*
             * A URL do PDF é a chave de deduplicação.
             *
             * O plano original usava unique(municipio_id, data_publicacao, edicao),
             * mas os dados reais desmentem isso: numa amostra de mil diários de
             * Campinas há apenas 997 pares data+edição distintos — documentos
             * diferentes compartilham data e número de edição (por exemplo, dois
             * em 2025-12-31 com edição 13737). Aquela restrição recusaria diários
             * legítimos. Já a URL é única nos mil, e termina em hash do conteúdo.
             */
            $table->string('url_original')->unique();
            $table->string('url_texto')->nullable();

            $table->string('caminho_arquivo_local')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('hash_sha256', 64)->nullable();
            $table->timestamp('scraped_at')->nullable();

            $table->string('status')->default(StatusDiario::Pendente->value);
            $table->text('erro_mensagem')->nullable();

            $table->timestamps();

            // Sustenta tanto a listagem cronológica quanto o filtro por período.
            $table->index(['municipio_id', 'data_publicacao']);
            $table->index(['municipio_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diarios');
    }
};
