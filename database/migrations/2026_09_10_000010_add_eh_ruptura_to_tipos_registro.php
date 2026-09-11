<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            // Marca qual TipoRegistro representa a pergunta "Ruptura" na grade de coleta (Fase 2,
            // ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9) — sempre a primeira coluna,
            // marcar um produto exclui ele das demais colunas da mesma linha.
            $table->boolean('eh_ruptura')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->dropColumn('eh_ruptura');
        });
    }
};
