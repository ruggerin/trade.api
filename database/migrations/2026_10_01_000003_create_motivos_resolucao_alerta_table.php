<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de motivos pra fechamento rápido de alerta (docs/56) — cadastro simples por
        // empresa, mesmo molde de ramos_atividade. Sem "ordem": lista curta, ordenada por
        // descrição já basta (mesmo raciocínio de RamoAtividade).
        Schema::create('motivos_resolucao_alerta', function (Blueprint $table): void {
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
        Schema::dropIfExists('motivos_resolucao_alerta');
    }
};
