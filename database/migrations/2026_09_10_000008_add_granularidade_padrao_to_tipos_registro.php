<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            // Nullable = sem regra (comportamento livre atual, o promotor escolhe o vínculo).
            // LINHA/PRODUTO — ver App\Enums\GranularidadeResposta.
            $table->string('granularidade_padrao', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->dropColumn('granularidade_padrao');
        });
    }
};
