<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/11-RASTREAMENTO-TEMPO-REAL.md §3.1 — só a posição mais recente, sem histórico (v1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->double('ultima_localizacao_latitude')->nullable();
            $table->double('ultima_localizacao_longitude')->nullable();
            $table->timestamp('ultima_localizacao_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn(['ultima_localizacao_latitude', 'ultima_localizacao_longitude', 'ultima_localizacao_em']);
        });
    }
};
