<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->foreignId('objetivo_visita_id')->nullable()->after('tipo_visita_id')->constrained('objetivos_visita');
        });

        Schema::table('agendas_visita', function (Blueprint $table): void {
            $table->foreignId('objetivo_visita_id')->nullable()->after('tipo_visita_id')->constrained('objetivos_visita');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('objetivo_visita_id');
        });

        Schema::table('agendas_visita', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('objetivo_visita_id');
        });
    }
};
