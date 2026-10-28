<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Escrita do ERP e webhooks da API de integração (docs/96 §7.2 e Fase 4):
 * - `integracao_idempotencia`: resposta guardada por `Idempotency-Key` (24 h). Reenviar o mesmo
 *   lote depois de um timeout devolve a mesma resposta, sem gravar de novo.
 * - `integracao_webhooks`: endereço do sistema do cliente que recebe avisos, com o segredo da
 *   assinatura HMAC (criptografado: precisa ser lido pra assinar).
 * - `integracao_webhook_entregas`: fila de avisos, entregue pelo `integracoes:entregar-webhooks`
 *   (a cada minuto, com novas tentativas) — a API não tem worker de fila, só o cron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integracao_idempotencia', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integracao_id')->constrained('integracoes')->cascadeOnDelete();
            $table->string('chave', 100);
            $table->string('assinatura', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('resposta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['integracao_id', 'chave']);
            $table->index('created_at');
        });

        Schema::create('integracao_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('integracao_id')->constrained('integracoes')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->json('eventos');
            $table->text('segredo');
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('falhas_seguidas')->default(0);
            $table->timestamp('desativado_em')->nullable();
            $table->string('desativado_motivo', 255)->nullable();
            $table->timestamp('ultima_entrega_em')->nullable();
            $table->timestamps();
        });

        Schema::create('integracao_webhook_entregas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('webhook_id')->constrained('integracao_webhooks')->cascadeOnDelete();
            $table->string('evento', 60);
            $table->json('payload');
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamp('desistiu_em')->nullable();
            $table->unsignedSmallInteger('ultimo_status')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->unsignedInteger('ultima_duracao_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entregue_em', 'desistiu_em', 'proxima_tentativa_em'], 'webhook_entregas_pendentes');
            $table->index(['webhook_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracao_webhook_entregas');
        Schema::dropIfExists('integracao_webhooks');
        Schema::dropIfExists('integracao_idempotencia');
    }
};
