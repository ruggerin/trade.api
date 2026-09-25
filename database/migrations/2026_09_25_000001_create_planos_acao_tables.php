<?php

use App\Enums\AcaoHistoricoPlanoAcao;
use App\Enums\StatusEtapaPlanoAcao;
use App\Enums\StatusPlanoAcao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plano de Ação — rastreamento de resolução multi-etapa de um problema operacional
        // (docs/37-PLANOS-DE-ACAO.md). MVP (§8 fase 1): nasce de um alerta, etapas montadas na
        // mão. As colunas de molde/artefato já existem pra fases seguintes não precisarem de
        // migração só pra ligar o que o §4.3/§4.6 já decidiu.
        Schema::create('planos_acao', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('titulo');
            $table->text('descricao')->nullable();
            // ALERTA | LIVRE (§4.2) — LIVRE só entra na fase 4, mas o enum já nasce com ele.
            $table->string('origem_tipo', 20);
            // Alerta de origem — um alerta pode ter vários planos ao longo do tempo
            // (reincidência, §5), mas só um ativo por vez (índice parcial abaixo).
            $table->foreignId('origem_registro_id')->nullable()->constrained('visita_registros');
            $table->enum('status', array_column(StatusPlanoAcao::cases(), 'value'))->default(StatusPlanoAcao::ABERTO->value);
            $table->date('prazo')->nullable();
            $table->foreignId('criado_por_id')->constrained('usuarios');
            $table->timestamp('concluido_em')->nullable();
            $table->foreignId('concluido_por_id')->nullable()->constrained('usuarios');
            $table->timestamp('cancelado_em')->nullable();
            $table->foreignId('cancelado_por_id')->nullable()->constrained('usuarios');
            $table->text('motivo_cancelamento')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
        });

        // §5: só um plano ABERTO/EM_ANDAMENTO por alerta — reincidência sempre vira plano novo,
        // nunca reabertura. Índice parcial garante no banco o que o controller já checa (race
        // de dois cliques simultâneos).
        DB::statement(
            "CREATE UNIQUE INDEX planos_acao_um_ativo_por_alerta ON planos_acao (origem_registro_id)
             WHERE origem_registro_id IS NOT NULL AND status IN ('ABERTO', 'EM_ANDAMENTO')"
        );

        // Sem empresa_id próprio — isolamento herdado de plano_acao_id (mesmo padrão de
        // visita_intervencoes/contrato_historicos).
        Schema::create('plano_acao_etapas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('plano_acao_id')->constrained('planos_acao')->cascadeOnDelete();
            $table->unsignedInteger('ordem');
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('prazo')->nullable();
            $table->enum('status', array_column(StatusEtapaPlanoAcao::cases(), 'value'))->default(StatusEtapaPlanoAcao::PENDENTE->value);
            // Responsável do sistema e/ou ator externo (vendedor, motorista) — o externo nunca
            // movimenta a etapa, alguém com planos_acao.movimentar_etapa acompanha por ele (§6).
            $table->foreignId('responsavel_id')->nullable()->constrained('usuarios');
            $table->string('responsavel_externo_nome')->nullable();
            $table->string('responsavel_externo_contato')->nullable();
            // Fase 3 (§4.4/§4.6): etapa que gera OS/formulário. NENHUM no MVP.
            $table->string('artefato_tipo', 30)->default('NENHUM');
            $table->unsignedBigInteger('artefato_id')->nullable();
            $table->boolean('auto_concluir_ao_finalizar_artefato')->default(false);
            $table->boolean('evidencia_obrigatoria')->default(false);
            $table->text('evidencia_texto')->nullable();
            $table->string('evidencia_arquivo_path')->nullable();
            // Motivo do último cancelamento/bloqueio — o histórico guarda todos, aqui só o atual
            // pra exibição direta no card da etapa.
            $table->text('motivo')->nullable();
            $table->timestamp('feita_em')->nullable();
            $table->foreignId('feita_por_id')->nullable()->constrained('usuarios');
            $table->timestamps();

            $table->unique(['plano_acao_id', 'ordem']);
        });

        // Log append-only (§4.9) — nunca editado, por isso só created_at.
        Schema::create('plano_acao_historicos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('plano_acao_id')->constrained('planos_acao')->cascadeOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('plano_acao_etapas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->enum('acao', array_column(AcaoHistoricoPlanoAcao::cases(), 'value'));
            $table->string('status_anterior', 30)->nullable();
            $table->string('status_novo', 30)->nullable();
            $table->text('motivo')->nullable();
            // Frase pronta pra exibição, montada no controller (mesma ideia de
            // contrato_historicos.descricao).
            $table->string('descricao');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_acao_historicos');
        Schema::dropIfExists('plano_acao_etapas');
        Schema::dropIfExists('planos_acao');
    }
};
