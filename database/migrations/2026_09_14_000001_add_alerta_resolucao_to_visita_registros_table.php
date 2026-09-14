<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-state de resolução de alerta (Painel de Atividades) — mesmo raciocínio de cancelado_em
 * na mesma tabela: nunca apaga nada, só marca quando (e quem) resolveu. Só tem efeito visível
 * quando a empresa liga o Parametro ATIVIDADES_ALERTA_REQUER_RESOLUCAO — ver
 * docs/17-PAINEL-ATIVIDADES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->timestamp('alerta_resolvido_em')->nullable()->after('cancelado_em');
            $table->foreignId('alerta_resolvido_por_id')->nullable()->after('alerta_resolvido_em')->constrained('usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alerta_resolvido_por_id');
            $table->dropColumn('alerta_resolvido_em');
        });
    }
};
