<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            // Nullable: só usado por quem quiser marcar um par antes/depois no registro geral
            // (sem produto vinculado) — um registro solto sem essa marcação continua válido.
            // Valores inline (não mais App\Enums\MomentoRegistro — removido em
            // migrate_momento_to_campo_customizado, que também derruba esta coluna de novo).
            $table->enum('momento', ['ANTES', 'DEPOIS'])
                ->nullable()
                ->after('tipo_registro');
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn('momento');
        });
    }
};
