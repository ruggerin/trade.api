<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rede de lojas (ex.: "Grupo Pão de Açúcar", "Assaí") — classificação de PontoVenda,
        // mesmo raciocínio de DepartamentoAuditoria: cadastro simples por empresa, cresce livre.
        Schema::create('redes_lojas', function (Blueprint $table): void {
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
        Schema::dropIfExists('redes_lojas');
    }
};
