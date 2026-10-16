<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/83 — "ainda está lá?": configuração do acompanhamento no formulário.
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->boolean('acompanha')->default(false);
            // Pergunta que dispara, pela CHAVE (o editor recria os campos a cada salvamento, então o
            // id muda; a chave não). Nula = todo registro do formulário abre acompanhamento.
            $table->string('acompanha_gatilho_chave', 100)->nullable();
            $table->string('acompanha_gatilho_valor')->nullable();
            // 0 = confere em toda visita; N = volta N dias (de calendário) depois da última resposta.
            $table->unsignedSmallInteger('acompanha_intervalo_dias')->default(0);
            $table->string('acompanha_rotulo_continua', 60)->nullable();
            $table->string('acompanha_rotulo_acabou', 60)->nullable();
            // CONFIRMA | FORMULARIO_PREENCHIDO | FORMULARIO_CEGO
            $table->string('acompanha_ao_continuar', 30)->default('CONFIRMA');
            $table->foreignId('acompanha_formulario_acabou_id')->nullable()->constrained('tipos_registro')->nullOnDelete();
            // Prazo máximo em dias; nulo = só termina por resposta.
            $table->unsignedSmallInteger('acompanha_prazo_dias')->nullable();
        });

        Schema::create('acompanhamentos_registro', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->foreignId('registro_origem_id')->constrained('visita_registros');
            // Chave do item (copiada do registro original) — acha "o mesmo item" pra substituir.
            $table->foreignId('produto_auditoria_id')->nullable()->constrained('produtos_auditoria')->nullOnDelete();
            $table->string('tipo_vinculo', 20)->nullable();
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria')->nullOnDelete();
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria')->nullOnDelete();
            // ABERTO | ENCERRADO | EXPIRADO | SUBSTITUIDO | ENCERRADO_PELO_GESTOR | CANCELADO
            $table->string('situacao', 30)->default('ABERTO');
            $table->timestamp('ultima_resposta_em')->nullable();
            $table->date('proxima_verificacao_em');
            $table->timestamp('encerrado_em')->nullable();
            $table->foreignId('encerrado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->date('expira_em')->nullable();
            $table->timestamps();

            $table->index(['ponto_venda_id', 'situacao']);
            $table->index(['empresa_id', 'situacao']);
        });

        Schema::create('acompanhamento_respostas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('acompanhamento_id')->constrained('acompanhamentos_registro')->cascadeOnDelete();
            $table->foreignId('visita_id')->constrained('visitas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            // CONTINUA | ACABOU | NAO_CONSEGUI_VER
            $table->string('resposta', 20);
            $table->foreignId('registro_id')->nullable()->constrained('visita_registros')->nullOnDelete();
            $table->timestamp('respondido_em');
            // false = chegou depois de o acompanhamento já ter fechado (resposta offline): só histórico.
            $table->boolean('aplicada')->default(true);
            $table->uuid('idempotency_key');
            $table->timestamps();

            $table->unique(['acompanhamento_id', 'idempotency_key']);
        });

        // Registro gerado ao "Continuar" ou na justificativa do "Acabou" — não abre acompanhamento novo.
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->foreignId('acompanhamento_id')->nullable()->constrained('acompanhamentos_registro')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acompanhamento_id');
        });
        Schema::dropIfExists('acompanhamento_respostas');
        Schema::dropIfExists('acompanhamentos_registro');
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acompanha_formulario_acabou_id');
            $table->dropColumn([
                'acompanha', 'acompanha_gatilho_chave', 'acompanha_gatilho_valor', 'acompanha_intervalo_dias',
                'acompanha_rotulo_continua', 'acompanha_rotulo_acabou', 'acompanha_ao_continuar', 'acompanha_prazo_dias',
            ]);
        });
    }
};
