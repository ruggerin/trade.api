<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/67 Fase 2 (§4.2):
 * - `dispositivos` deixa de ser 1:1 e vira histórico — 1 linha ativa por usuário (índice único
 *   parcial em desvinculado_em IS NULL), o identificador do aparelho só como hash SHA-256.
 * - `eventos_seguranca`: append-only (login, troca, bloqueio, reuso de token, integridade...).
 * - `solicitacoes_aparelho`: pedido de liberação quando o promotor tenta um aparelho novo e a troca
 *   self-service não está disponível (423 APARELHO_BLOQUEADO).
 * - `personal_access_tokens`: token preso ao aparelho (dispositivo_id) e revogação com motivo
 *   (o app mostra "Sua conta entrou em outro aparelho (Moto G, 14:32)" em vez de só voltar pro login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->string('identificador_hash', 64)->nullable();
            $table->string('plataforma', 20)->nullable();
            $table->string('versao_so', 50)->nullable();
            $table->string('versao_app', 30)->nullable();
            // Origem do vínculo: PRIMEIRO, TROCA_PROPRIA (conta pra janela de troca), LIBERADO_ADMIN.
            $table->string('origem', 20)->default('PRIMEIRO');
            // Fase 3: chave pública (P-256, PEM) que assina os requests; status da atestação/integridade.
            $table->text('chave_publica')->nullable();
            $table->string('integridade_status', 30)->nullable();
            $table->json('integridade_detalhes')->nullable();
            $table->timestamp('vinculado_em')->nullable();
            $table->timestamp('desvinculado_em')->nullable();
            $table->foreignId('desvinculado_por')->nullable()->constrained('usuarios');
            $table->string('motivo', 40)->nullable();
        });

        foreach (DB::table('dispositivos')->get() as $linha) {
            DB::table('dispositivos')->where('id', $linha->id)->update([
                'empresa_id' => DB::table('usuarios')->where('id', $linha->usuario_id)->value('empresa_id'),
                'identificador_hash' => hash('sha256', $linha->identificador),
                'vinculado_em' => $linha->created_at ?? now(),
            ]);
        }

        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->dropUnique(['usuario_id']);
            $table->dropColumn('identificador');
            $table->index(['usuario_id', 'vinculado_em']);
        });
        DB::statement('CREATE UNIQUE INDEX dispositivos_um_ativo_por_usuario ON dispositivos (usuario_id) WHERE desvinculado_em IS NULL');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->timestamp('revogado_em')->nullable();
            $table->string('revogado_motivo', 40)->nullable();
            $table->json('revogado_detalhe')->nullable();
        });
        // Token mobile já existente → aparelho ativo do dono (o único que podia ter até aqui).
        DB::statement("UPDATE personal_access_tokens t SET dispositivo_id = d.id
            FROM dispositivos d
            WHERE t.tokenable_id = d.usuario_id AND t.canal = 'mobile' AND d.desvinculado_em IS NULL");

        Schema::create('eventos_seguranca', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->string('tipo', 40);
            $table->json('detalhes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['empresa_id', 'created_at']);
            $table->index(['usuario_id', 'tipo', 'created_at']);
        });

        Schema::create('solicitacoes_aparelho', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('identificador_hash', 64);
            $table->string('nome')->nullable();
            $table->string('plataforma', 20)->nullable();
            // PENDENTE → APROVADA (o aparelho atual é desvinculado e este pode entrar) | RECUSADA.
            // USADA quando o aparelho aprovado de fato loga.
            $table->string('status', 20)->default('PENDENTE');
            $table->foreignId('decidido_por')->nullable()->constrained('usuarios');
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'status']);
            $table->index(['usuario_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_aparelho');
        Schema::dropIfExists('eventos_seguranca');
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dispositivo_id');
            $table->dropColumn(['revogado_em', 'revogado_motivo', 'revogado_detalhe']);
        });
        // Volta pro 1:1: só a linha ativa de cada usuário sobrevive.
        DB::table('dispositivos')->whereNotNull('desvinculado_em')->delete();
        DB::statement('DROP INDEX IF EXISTS dispositivos_um_ativo_por_usuario');
        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->string('identificador')->default('');
        });
        DB::statement('UPDATE dispositivos SET identificador = identificador_hash');
        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->dropIndex(['usuario_id', 'vinculado_em']);
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropConstrainedForeignId('desvinculado_por');
            $table->dropColumn([
                'uuid', 'identificador_hash', 'plataforma', 'versao_so', 'versao_app', 'origem', 'chave_publica',
                'integridade_status', 'integridade_detalhes', 'vinculado_em', 'desvinculado_em', 'motivo',
            ]);
            $table->unique('usuario_id');
        });
    }
};
