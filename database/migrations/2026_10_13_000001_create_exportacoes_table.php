<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log append-only de exportações (docs/68 §4.4): quem levou dado pra fora, o quê, com que filtro e
 * quantas linhas. Nunca atualizado nem apagado pela aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('rotina', 60);
            $table->string('formato', 10);
            $table->json('filtros')->nullable();
            $table->unsignedInteger('linhas')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacoes');
    }
};
