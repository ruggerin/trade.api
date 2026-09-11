<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            // Preenchido quando o gestor rejeita uma solicitação (criação/reagendamento/
            // cancelamento) do promotor — sem isso, o promotor via o status voltar pro normal
            // sem saber por quê. Limpo sempre que uma nova solicitação nasce pra mesma OS, pra
            // não deixar motivo velho colado numa tentativa nova. Ver
            // docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
            $table->text('motivo_rejeicao')->nullable()->after('observacao');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropColumn('motivo_rejeicao');
        });
    }
};
