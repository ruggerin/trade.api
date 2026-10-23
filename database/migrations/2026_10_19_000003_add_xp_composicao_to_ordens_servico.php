<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // docs/88 §5.1: peso de cada parte do XP da visita, congelado quando a OS nasce (parâmetros
    // XP_PESO_*). Nulo = OS anterior aos pesos configuráveis, vale a composição padrão.
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->json('xp_composicao')->nullable()->after('xp_previsto');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', fn (Blueprint $table) => $table->dropColumn('xp_composicao'));
    }
};
