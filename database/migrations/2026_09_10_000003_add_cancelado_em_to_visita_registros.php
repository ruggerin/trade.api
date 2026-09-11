<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelamento de registro pelo próprio promotor (ou ADMIN/GESTOR) — soft, nunca hard delete:
 * mantém o registro (e a foto) como rastro histórico, só marca quando foi cancelado. Ver
 * App\Support\CancelamentoRegistro e VisitaRegistroController::cancelar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->timestamp('cancelado_em')->nullable()->after('valores_campos');
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn('cancelado_em');
        });
    }
};
