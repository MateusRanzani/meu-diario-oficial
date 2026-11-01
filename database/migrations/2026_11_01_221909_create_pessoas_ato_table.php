<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pessoas_ato', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ato_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('cargo')->nullable();
            $table->string('matricula')->nullable();
            $table->timestamps();

            $table->index('ato_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pessoas_ato');
    }
};
