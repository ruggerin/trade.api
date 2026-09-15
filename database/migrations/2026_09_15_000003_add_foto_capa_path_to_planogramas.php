<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capa visual do planograma — enviada pelo admin (foto real da prateleira) ou gerada
        // automaticamente a partir da grade no editor (screenshot do DOM, sobe pelo mesmo
        // endpoint). Ver docs/22-PLANOGRAMA.md.
        Schema::table('planogramas', function (Blueprint $table): void {
            $table->string('foto_capa_path')->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('planogramas', function (Blueprint $table): void {
            $table->dropColumn('foto_capa_path');
        });
    }
};
