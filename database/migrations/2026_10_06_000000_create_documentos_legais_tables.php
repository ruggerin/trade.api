<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aceite dos Termos de Uso e da Política de Privacidade — docs/58-ACEITE-TERMOS-E-PRIVACIDADE.md §4.
 *
 * `documentos_legais`: cada versão publicada, com o texto completo e o hash — global (não é por
 * empresa) e nunca editada; mudar o texto = publicar versão nova (`documentos-legais:publicar`).
 *
 * `aceites_documentos_legais`: append-only, uma linha por documento aceito, apontando pra versão
 * exata. FK `restrict` de propósito: desativar/excluir o usuário não pode apagar a prova do aceite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_legais', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30);
            // Data de "Última atualização" do texto, ex.: 2026-10-06.
            $table->string('versao', 20);
            $table->text('conteudo');
            $table->char('hash_sha256', 64);
            $table->timestampTz('publicado_em');
            $table->timestampsTz();

            $table->unique(['tipo', 'versao']);
            $table->index(['tipo', 'publicado_em']);
        });

        Schema::create('aceites_documentos_legais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('documento_legal_id')->constrained('documentos_legais')->restrictOnDelete();
            $table->timestampTz('aceito_em');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            // MOBILE | ADMIN — App\Support\Adesao::appDaRequisicao (header X-Client).
            $table->string('app', 10);
            $table->string('dispositivo_identificador')->nullable();

            $table->unique(['usuario_id', 'documento_legal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceites_documentos_legais');
        Schema::dropIfExists('documentos_legais');
    }
};
