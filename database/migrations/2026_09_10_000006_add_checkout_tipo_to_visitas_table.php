<?php

use App\Enums\CheckoutTipo;
use App\Enums\StatusVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Como o checkout foi feito: PROMOTOR (fluxo normal pelo app) ou ADMIN (forçado por um
        // gestor, sem GPS — ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md). NULL enquanto
        // ABERTA. Deixa o relatório de custo/hora distinguir checkout real de forçado sem join.
        Schema::table('visitas', function (Blueprint $table): void {
            $table->enum('checkout_tipo', array_column(CheckoutTipo::cases(), 'value'))
                ->nullable()
                ->after('fim_distancia_metros');
        });

        // Visitas já finalizadas antes desta migration foram todas fechadas pelo promotor.
        DB::table('visitas')
            ->where('status', StatusVisita::FINALIZADA->value)
            ->update(['checkout_tipo' => CheckoutTipo::PROMOTOR->value]);
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->dropColumn('checkout_tipo');
        });
    }
};
