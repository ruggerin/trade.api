<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot pura (sem uuid/empresa_id próprios) — mesmo padrão de promotor_pontos_venda.
        // `ordem` só importa quando uma resposta tem várias fotos (mostrar na ordem em que
        // foram tiradas); é irrelevante quando a mesma foto é compartilhada entre respostas
        // diferentes. Ver docs/21-EVIDENCIA-EM-FOTOS.md.
        Schema::create('visita_registro_imagem', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visita_registro_id')->constrained('visita_registros')->cascadeOnDelete();
            $table->foreignId('imagem_registro_id')->constrained('imagens_registro')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->nullable();
            $table->timestamps();

            $table->unique(['visita_registro_id', 'imagem_registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_registro_imagem');
    }
};
