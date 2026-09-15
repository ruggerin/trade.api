<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Referência visual de layout de prateleira/expositor — ver docs/22-PLANOGRAMA.md.
        // Independente de Campanha de propósito (reutilizável por N campanhas ao longo do
        // tempo, ver campanha_planogramas na Fase 2).
        Schema::create('planogramas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('descricao');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planogramas');
    }
};
