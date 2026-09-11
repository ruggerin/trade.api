<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objetivos_visita', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            // Ex. "Reposição", "Negociação", "Retomada de volume" — mesmo desenho de
            // tipos_visita, mas sem cor (a tag colorida já é o tipo de visita; objetivo é o
            // motivo de negócio, eixo diferente). Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
            $table->string('descricao');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objetivos_visita');
    }
};
