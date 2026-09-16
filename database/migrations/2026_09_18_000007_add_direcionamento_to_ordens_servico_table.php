<?php

use App\Enums\OrigemOrdemServico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->foreignId('direcionamento_id')->nullable()->after('campanha_id')->constrained('direcionamentos');
        });

        // Mesma pegadinha de sempre (ver 2026_09_18_000002) — o CHECK de "origem" precisa ser
        // recriado quando um case novo entra no enum PHP.
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        $valores = "'".implode("', '", array_column(OrigemOrdemServico::cases(), 'value'))."'";
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ({$valores}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ('MANUAL', 'CAMPANHA', 'AGENDA', 'CONTRATO'))");

        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('direcionamento_id');
        });
    }
};
