<?php

use App\Enums\StatusOrdemServico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O CHECK de "status" foi criado só com os 4 valores originais — precisa recriar pra
        // aceitar os três novos de autonomia do promotor (ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md).
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_status_check');
        $valores = "'".implode("', '", array_column(StatusOrdemServico::cases(), 'value'))."'";
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_status_check CHECK (status IN ({$valores}))");

        Schema::table('ordens_servico', function (Blueprint $table): void {
            // Só preenchidas enquanto um reagendamento está REAGENDAMENTO_SOLICITADO — prazo
            // oficial (prazo_inicio/prazo_fim) continua intacto até o gestor aprovar.
            $table->timestamp('prazo_inicio_proposto')->nullable()->after('prazo_fim');
            $table->timestamp('prazo_fim_proposto')->nullable()->after('prazo_inicio_proposto');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropColumn(['prazo_inicio_proposto', 'prazo_fim_proposto']);
        });

        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_status_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_status_check CHECK (status IN ('PENDENTE', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA'))");
    }
};
