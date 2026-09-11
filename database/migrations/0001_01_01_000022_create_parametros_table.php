<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações genéricas chave/valor por empresa (ex.: raio de check-in
        // customizável, textos, flags) — o mobile baixa a lista inteira e guarda em cache
        // local, evitando requisição repetida. Ver docs/02-API-BACKEND.md.
        Schema::create('parametros', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('chave', 100);
            $table->text('valor');
            $table->string('descricao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
    }
};
