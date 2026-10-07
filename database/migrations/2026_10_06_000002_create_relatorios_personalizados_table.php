<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gerador de relatórios — docs/60-GERADOR-DE-RELATORIOS.md §4. Um relatório é uma definição
 * declarativa (jsonb, §3.1) executada contra uma entidade de uma lista fechada no backend
 * (App\Relatorios). `padrao` = relatório do sistema semeado por `relatorios:completar`, reconhecido
 * pela `chave`; o cliente não edita, só duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relatorios_personalizados', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // Criador; null nos padrão (são do sistema).
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('nome', 120);
            $table->string('descricao', 500)->nullable();
            $table->string('entidade', 30);
            $table->jsonb('definicao');
            // true = a empresa toda vê; false = só o criador.
            $table->boolean('compartilhado')->default(false);
            $table->boolean('padrao')->default(false);
            // Slug estável dos padrão — o que o seed reconhece.
            $table->string('chave', 80)->nullable();
            // Versão do padrão semeado: maior no catálogo = o seed atualiza.
            $table->unsignedInteger('versao')->default(1);
            $table->timestamps();

            $table->index(['empresa_id', 'padrao']);
        });

        DB::statement('CREATE UNIQUE INDEX relatorios_personalizados_empresa_chave_unique ON relatorios_personalizados (empresa_id, chave) WHERE chave IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorios_personalizados');
    }
};
