<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de API para integrações (docs/96 §4):
 * - `integracoes`: o dono do token é a integração da empresa, não uma pessoa — não cai quando
 *   alguém sai. `escopos` diz o que o token acessa (App\Enums\EscopoIntegracao).
 * - `personal_access_tokens.prefixo_visivel`: o começo do token (`horus_live_8f3a`) pra tela
 *   mostrar qual é qual sem guardar o segredo (só o SHA-256 fica no banco, como no resto do Sanctum).
 * - `integracao_requisicoes`: auditoria de cada chamada, apagada depois de 90 dias
 *   (`integracoes:limpar-requisicoes`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integracoes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->text('descricao')->nullable();
            $table->json('escopos');
            $table->json('ips_permitidos')->nullable();
            $table->unsignedInteger('limite_por_minuto')->default(120);
            $table->boolean('ativa')->default(true);
            $table->foreignId('criada_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('revogada_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('revogada_em')->nullable();
            $table->text('revogada_motivo')->nullable();
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->string('ultimo_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'revogada_em']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('prefixo_visivel', 24)->nullable();
        });

        Schema::create('integracao_requisicoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integracao_id')->constrained('integracoes')->cascadeOnDelete();
            $table->string('metodo', 8);
            $table->string('rota', 255);
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('duracao_ms');
            $table->unsignedInteger('registros')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['integracao_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracao_requisicoes');
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('prefixo_visivel');
        });
        Schema::dropIfExists('integracoes');
    }
};
