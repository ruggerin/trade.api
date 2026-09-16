<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direcionamentos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('descricao');
            $table->timestamp('vigencia_inicio');
            $table->timestamp('vigencia_fim');
            // false = cancela em cascata as OS PENDENTE geradas por ele e para de gerar OS novas
            // — ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 6.
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcionamentos');
    }
};
