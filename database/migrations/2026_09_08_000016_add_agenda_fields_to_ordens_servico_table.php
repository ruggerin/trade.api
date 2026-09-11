<?php

use App\Enums\PrioridadeVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A checagem de "origem" foi criada com só MANUAL/CAMPANHA (ver
        // 2026_09_08_000007_create_ordens_servico_table.php) — precisa recriar o CHECK
        // constraint pra aceitar o novo valor AGENDA. Nome confirmado no banco de dev
        // (`ordens_servico_origem_check`, convenção padrão do Postgres pra CHECK de coluna).
        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ('MANUAL', 'CAMPANHA', 'AGENDA'))");

        Schema::table('ordens_servico', function (Blueprint $table): void {
            // Nullable e válidos pra qualquer origem — um gestor criando OS manual também pode
            // marcar tipo/prioridade/horário, não é exclusivo de origem AGENDA.
            $table->foreignId('tipo_visita_id')->nullable()->after('campanha_id')->constrained('tipos_visita');
            $table->foreignId('agenda_visita_id')->nullable()->after('tipo_visita_id')->constrained('agendas_visita');
            $table->enum('prioridade', array_column(PrioridadeVisita::cases(), 'value'))->nullable()->after('agenda_visita_id');
            $table->time('horario_previsto')->nullable()->after('prioridade');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tipo_visita_id');
            $table->dropConstrainedForeignId('agenda_visita_id');
            $table->dropColumn(['prioridade', 'horario_previsto']);
        });

        DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ordens_servico_origem_check');
        DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ordens_servico_origem_check CHECK (origem IN ('MANUAL', 'CAMPANHA'))");
    }
};
