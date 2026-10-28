<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria das configurações da empresa: toda mudança em `parametros` (valor ou ligado/desligado)
 * vira uma linha aqui, com o antes, o depois, quem e de onde. Gravado pelo próprio model
 * (App\Models\Parametro), então qualquer caminho — tela, Avançado, suporte, comando — fica registrado.
 * Não tem dado anterior: começa vazio a partir do deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametro_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('chave', 100);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_novo')->nullable();
            $table->boolean('ativo_anterior')->nullable();
            $table->boolean('ativo_novo')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            // Nome guardado junto: o histórico continua legível se o usuário for apagado.
            $table->string('usuario_nome', 255)->nullable();
            $table->string('origem', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['empresa_id', 'created_at']);
            $table->index(['empresa_id', 'chave', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametro_historico');
    }
};
