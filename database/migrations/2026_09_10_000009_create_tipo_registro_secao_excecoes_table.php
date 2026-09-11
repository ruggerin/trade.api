<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exceção de granularidade por (TipoRegistro × Seção) — sobrepõe
 * `tipos_registro.granularidade_padrao` só pra essa seção. Ver
 * docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4, opção (a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_registro_secao_excecoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('secao_auditoria_id')->constrained('secoes_auditoria')->cascadeOnDelete();
            $table->string('granularidade', 10);
            $table->timestamps();
            $table->unique(['tipo_registro_id', 'secao_auditoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_registro_secao_excecoes');
    }
};
