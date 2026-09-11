<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            // Mesmo padrão de limite_usuarios/limite_pontos_venda: null = sem limite. Conta
            // usuários PROMOTOR ativos (cada um trava 1 dispositivo por vez, ver
            // AuthController::login) — cobrança por licença de dispositivo, ver
            // docs/02-API-BACKEND.md.
            $table->integer('limite_licencas')->nullable()->after('limite_pontos_venda');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn('limite_licencas');
        });
    }
};
