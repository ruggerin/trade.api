<?php

use App\Enums\CheckoutTipo;
use App\Enums\StatusVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout pela saída detectada (docs/92 §9):
 * - `checkout_tipo` ganha SAIDA_DETECTADA e AUTOMATICO (no Postgres o enum do Laravel é um CHECK).
 * - `checkout_no_raio`: o checkout foi dentro do CHECKOUT_RAIO_METROS (null = empresa sem raio).
 * - `tempo_confirmado_gps`: entrada e saída dentro do raio (null = empresa sem raio).
 * - `saida_avisada_em`: push "você saiu sem finalizar" já mandado (não repete).
 * - `saida_contestada_em`: o promotor tocou em "Ainda estou na loja" (GPS pode errar).
 *
 * Também corrige o passado: o checkout do promotor nunca gravava `checkout_tipo = PROMOTOR` (só o
 * forçado gravava ADMIN), então visita FINALIZADA sem tipo foi fechada pelo promotor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $valores = implode(', ', array_map(fn ($v) => "'{$v}'", array_column(CheckoutTipo::cases(), 'value')));
            DB::statement('ALTER TABLE visitas DROP CONSTRAINT IF EXISTS visitas_checkout_tipo_check');
            DB::statement("ALTER TABLE visitas ADD CONSTRAINT visitas_checkout_tipo_check CHECK (checkout_tipo IN ({$valores}))");
        }

        Schema::table('visitas', function (Blueprint $table): void {
            $table->boolean('checkout_no_raio')->nullable()->after('checkout_tipo');
            $table->boolean('tempo_confirmado_gps')->nullable()->after('checkout_no_raio');
            $table->timestamp('saida_avisada_em')->nullable()->after('tempo_confirmado_gps');
            $table->timestamp('saida_contestada_em')->nullable()->after('saida_avisada_em');
        });

        DB::table('visitas')
            ->where('status', StatusVisita::FINALIZADA->value)
            ->whereNull('checkout_tipo')
            ->update(['checkout_tipo' => CheckoutTipo::PROMOTOR->value]);
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->dropColumn(['checkout_no_raio', 'tempo_confirmado_gps', 'saida_avisada_em', 'saida_contestada_em']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::table('visitas')
                ->whereIn('checkout_tipo', [CheckoutTipo::SAIDA_DETECTADA->value, CheckoutTipo::AUTOMATICO->value])
                ->update(['checkout_tipo' => CheckoutTipo::PROMOTOR->value]);
            DB::statement('ALTER TABLE visitas DROP CONSTRAINT IF EXISTS visitas_checkout_tipo_check');
            DB::statement("ALTER TABLE visitas ADD CONSTRAINT visitas_checkout_tipo_check CHECK (checkout_tipo IN ('PROMOTOR', 'ADMIN'))");
        }
    }
};
