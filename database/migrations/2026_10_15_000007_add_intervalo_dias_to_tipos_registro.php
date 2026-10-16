<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Intervalo entre cobranças da Ação (docs/80 §5.2): só vira pendência se não houve registro
        // deste formulário na loja nos últimos N dias. 0 = toda visita (comportamento de antes).
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->unsignedSmallInteger('intervalo_dias')->default(0)->after('escopo_marca_id');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn('intervalo_dias');
        });
    }
};
