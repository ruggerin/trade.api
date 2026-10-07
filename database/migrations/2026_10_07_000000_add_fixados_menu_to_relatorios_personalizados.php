<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatórios fixados no menu — docs/63-ORGANIZACAO-DO-MENU-E-NOME-LOJA.md §1.7. Dois alcances:
 * da empresa (coluna aqui, só em padrão/compartilhado) e "meu" (tabela por usuário). Excluir o
 * relatório ou o usuário leva o fixado junto (cascade), sem link quebrado no menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorios_personalizados', function (Blueprint $table) {
            $table->boolean('fixado_empresa')->default(false);
            $table->unsignedInteger('ordem_menu')->nullable();
        });

        Schema::create('relatorios_fixados_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('relatorio_personalizado_id')->constrained('relatorios_personalizados')->cascadeOnDelete();
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['usuario_id', 'relatorio_personalizado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorios_fixados_usuario');
        Schema::table('relatorios_personalizados', function (Blueprint $table) {
            $table->dropColumn(['fixado_empresa', 'ordem_menu']);
        });
    }
};
