<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bloco_id')->nullable()->constrained('blocos')->nullOnDelete();

            $table->string('placa')->nullable();
            $table->string('auto_infracao')->nullable();
            $table->string('codigo')->nullable();
            $table->date('data_infracao')->nullable();
            $table->decimal('valor', 10, 2)->nullable();

            $table->timestamps();

            $table->index(['diario_id']);
            $table->index('placa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multas');
    }
};
