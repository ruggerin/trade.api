<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mesma situação de 2026_09_08_000016_add_agenda_fields_to_ordens_servico_table.php: o
        // CHECK de "origem" precisa ser recriado pra aceitar CONTRATO. Ver
        // App\Console\Commands\GerarOrdensServicoPorContrato e docs/07-ORDEM-DE-SERVICO.md §5.
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ('MANUAL', 'CAMPANHA', 'AGENDA', 'CONTRATO'))");

        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->foreignId('contrato_id')->nullable()->after('agenda_visita_id')->constrained('contratos');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contrato_id');
        });

        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ('MANUAL', 'CAMPANHA', 'AGENDA'))");
    }
};
