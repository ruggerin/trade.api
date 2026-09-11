<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campanhas_auditoria', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('descricao', 155);
            $table->text('observacao')->nullable();
            $table->string('layout', 155)->nullable();
            $table->timestamp('vigencia_inicio');
            $table->timestamp('vigencia_fim');
            $table->text('restricao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->text('exclusividade')->nullable();
            $table->unsignedInteger('frequencia_dias')->nullable();
            $table->boolean('execucao_recorrente')->default(true);
            $table->boolean('possui_restricao')->default(false);
            $table->boolean('possui_exclusividade')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanhas_auditoria');
    }
};
