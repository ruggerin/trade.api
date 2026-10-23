<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Régua de performance com XP (docs/88). Tudo que a régua grava ou congela.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §2.1 — recurso de plano, só o SUPERADMIN liga (mesmo molde de pedidos_venda_habilitado).
        Schema::table('empresas', function (Blueprint $table): void {
            $table->boolean('controle_xp_habilitado')->default(false);
        });

        // §4.1 — quanto vale uma visita em cada tipo de loja. Cadastro da empresa.
        Schema::create('classes_ponto_venda', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome', 60);
            $table->string('icone', 60)->nullable();
            $table->string('cor', 20)->nullable();
            $table->unsignedInteger('xp_base');
            $table->unsignedInteger('duracao_padrao_minutos');
            $table->boolean('eh_padrao')->default(false);
            $table->unsignedInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
        // Só uma classe padrão por empresa.
        DB::statement('CREATE UNIQUE INDEX classes_ponto_venda_padrao_unico ON classes_ponto_venda (empresa_id) WHERE eh_padrao');

        Schema::table('pontos_venda', function (Blueprint $table): void {
            // NULL = classe padrão da empresa.
            $table->foreignId('classe_id')->nullable()->constrained('classes_ponto_venda')->nullOnDelete();
            // Rotina `xp:sugerir-classes`; o gestor aceita ou não (o tempo nunca muda a classe sozinho).
            $table->foreignId('classe_sugerida_id')->nullable()->constrained('classes_ponto_venda')->nullOnDelete();
        });

        // §6 — valor congelado quando a OS nasce: mudar a classe depois não reescreve o passado.
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->foreignId('classe_id')->nullable()->constrained('classes_ponto_venda')->nullOnDelete();
            $table->unsignedInteger('xp_previsto')->nullable();
            // doc 87 (mínimo pra régua): duração prevista, sugerida pela classe.
            $table->unsignedInteger('duracao_prevista_minutos')->nullable();
        });

        // §9.1 — XP de iniciativa configurado no formulário; §10 — avaliação do gestor (doc 33).
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->unsignedSmallInteger('xp_iniciativa')->default(0);
            $table->unsignedSmallInteger('xp_bonus_acao')->default(0);
            $table->unsignedSmallInteger('xp_teto_por_visita')->nullable();
            $table->unsignedSmallInteger('xp_intervalo_dias')->default(0);
            $table->boolean('exige_avaliacao')->default(false);
        });

        // doc 33 (mínimo): aprovar ou reprovar o formulário, com motivo. NULL = não passa por avaliação.
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->string('avaliacao_status', 20)->nullable();
            $table->foreignId('avaliado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('avaliado_em')->nullable();
            $table->text('avaliacao_motivo')->nullable();
            $table->index(['avaliacao_status']);
        });

        // Completude declarada pelo app no check-out (o mesmo "N de M obrigatórias" que o promotor vê).
        Schema::table('visitas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('obrigatorias_feitas')->nullable();
            $table->unsignedSmallInteger('obrigatorias_total')->nullable();
        });

        // §9.2 — alerta resolvido com motivo "procedente" conta como informação que virou ação.
        Schema::table('motivos_resolucao_alerta', function (Blueprint $table): void {
            $table->boolean('procedente')->default(false);
        });

        // §11/§12.4 — equipe do supervisor (doc 73): grupo da liga e nível "equipe" da régua.
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->foreignId('supervisor_id')->nullable()->constrained('supervisores')->nullOnDelete();
        });

        // §14 — lançamentos append-only. Pontos nunca são editados; só o status de um PENDENTE é
        // resolvido (OFICIAL ou DESCARTADO), com data.
        Schema::create('xp_lancamentos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('ordem_servico_id')->nullable()->constrained('ordens_servico')->nullOnDelete();
            $table->foreignId('visita_id')->nullable()->constrained('visitas')->nullOnDelete();
            $table->foreignId('visita_registro_id')->nullable()->constrained('visita_registros')->nullOnDelete();
            $table->string('natureza', 20);
            $table->string('categoria', 40);
            $table->integer('pontos');
            $table->string('status', 20)->default('OFICIAL');
            $table->date('competencia');
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolvido_em')->nullable();

            $table->index(['empresa_id', 'competencia']);
            $table->index(['usuario_id', 'competencia']);
            $table->index(['visita_id']);
            $table->index(['ordem_servico_id']);
        });

        // §14 — resumo por promotor e dia (equipe e empresa somam daqui).
        Schema::create('xp_resumos_diarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->date('dia');
            $table->integer('possivel')->default(0);
            $table->integer('realizado')->default(0);
            $table->integer('iniciativa')->default(0);
            $table->integer('pendente')->default(0);
            $table->jsonb('perdas')->default('{}');
            $table->unsignedSmallInteger('visitas_previstas')->default(0);
            $table->unsignedSmallInteger('visitas_feitas')->default(0);
            $table->unsignedSmallInteger('visitas_completas')->default(0);
            // Não feitas com responsável LOJA/EMPRESA ou justificadas: protegem a ofensiva (§12.3).
            $table->unsignedSmallInteger('visitas_protegidas')->default(0);
            $table->timestamp('atualizado_em')->nullable();

            $table->unique(['usuario_id', 'dia']);
            $table->index(['empresa_id', 'dia']);
        });

        // §8 — classificação append-only: reclassificar cria linha nova.
        Schema::create('xp_ocorrencia_classificacoes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('xp_lancamento_id')->constrained('xp_lancamentos')->cascadeOnDelete();
            $table->boolean('justificada');
            // Causa: reaproveita o cadastro de motivos de não execução (doc 59).
            $table->foreignId('motivo_nao_execucao_id')->nullable()->constrained('motivos_nao_execucao')->nullOnDelete();
            $table->text('comentario')->nullable();
            $table->foreignId('plano_acao_id')->nullable()->constrained('planos_acao')->nullOnDelete();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['xp_lancamento_id', 'id']);
        });

        // §12.4 — semana da liga fechada: não muda mais, mesmo com XP aprovado depois.
        Schema::create('xp_ligas_semanais', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('supervisor_id')->nullable()->constrained('supervisores')->nullOnDelete();
            $table->date('semana_inicio');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->unsignedSmallInteger('posicao');
            $table->integer('xp');
            $table->integer('plano');
            $table->integer('iniciativa');
            $table->integer('possivel');
            $table->unsignedSmallInteger('visitas_completas');
            $table->timestamp('fechado_em');

            $table->unique(['usuario_id', 'semana_inicio']);
            $table->index(['empresa_id', 'semana_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_ligas_semanais');
        Schema::dropIfExists('xp_ocorrencia_classificacoes');
        Schema::dropIfExists('xp_resumos_diarios');
        Schema::dropIfExists('xp_lancamentos');
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropConstrainedForeignId('supervisor_id'));
        Schema::table('motivos_resolucao_alerta', fn (Blueprint $t) => $t->dropColumn('procedente'));
        Schema::table('visitas', fn (Blueprint $t) => $t->dropColumn(['obrigatorias_feitas', 'obrigatorias_total']));
        Schema::table('visita_registros', function (Blueprint $t): void {
            $t->dropIndex(['avaliacao_status']);
            $t->dropConstrainedForeignId('avaliado_por_id');
            $t->dropColumn(['avaliacao_status', 'avaliado_em', 'avaliacao_motivo']);
        });
        Schema::table('tipos_registro', fn (Blueprint $t) => $t->dropColumn(['xp_iniciativa', 'xp_bonus_acao', 'xp_teto_por_visita', 'xp_intervalo_dias', 'exige_avaliacao']));
        Schema::table('ordens_servico', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('classe_id');
            $t->dropColumn(['xp_previsto', 'duracao_prevista_minutos']);
        });
        Schema::table('pontos_venda', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('classe_id');
            $t->dropConstrainedForeignId('classe_sugerida_id');
        });
        DB::statement('DROP INDEX IF EXISTS classes_ponto_venda_padrao_unico');
        Schema::dropIfExists('classes_ponto_venda');
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('controle_xp_habilitado'));
    }
};
