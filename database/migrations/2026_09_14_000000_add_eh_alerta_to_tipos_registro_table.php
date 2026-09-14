<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            // A empresa marca quais tipos disparam alerta no Painel de Atividades (Ruptura,
            // Avaria, Vencimento próximo, Ação da concorrência etc.) — mesmo padrão de
            // eh_ruptura, nada fixo no código. Ver docs/17-PAINEL-ATIVIDADES.md.
            $table->boolean('eh_alerta')->default(false)->after('eh_ruptura');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn('eh_alerta');
        });
    }
};
