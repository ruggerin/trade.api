<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Só faz sentido pra user_type = PROMOTOR, mesmo padrão de perfil_id (só faz sentido pra
// GESTOR) — ver docs/08-CENTRO-DE-CUSTO.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->foreignId('centro_custo_id')->nullable()->after('perfil_id')->constrained('centros_custo');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('centro_custo_id');
        });
    }
};
