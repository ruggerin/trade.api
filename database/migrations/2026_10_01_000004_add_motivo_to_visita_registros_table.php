<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Motivo do fechamento rápido (docs/56) — pelo menos um dos dois preenchido quando o
        // alerta é resolvido via VisitaRegistroController::resolverAlerta (validado no
        // ResolverAlertaRequest, não aqui). alerta_motivo_texto também serve de complemento
        // livre ao motivo_id escolhido, não só de alternativa a ele.
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->foreignId('alerta_motivo_id')->nullable()->after('alerta_resolvido_por_id')
                ->constrained('motivos_resolucao_alerta')->nullOnDelete();
            $table->text('alerta_motivo_texto')->nullable()->after('alerta_motivo_id');
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alerta_motivo_id');
            $table->dropColumn('alerta_motivo_texto');
        });
    }
};
