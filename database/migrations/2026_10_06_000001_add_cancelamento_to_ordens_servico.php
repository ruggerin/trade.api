<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rastro do cancelamento (docs/59 §3.3) — até aqui cancelar só mudava `status`, sem
        // autor, data nem motivo, e a OS cancelada sumia do relatório sem deixar marca.
        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->timestamp('cancelada_em')->nullable();
            $table->foreignId('cancelada_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('motivo_cancelamento_id')->nullable()->constrained('motivos_nao_execucao')->nullOnDelete();
            $table->text('motivo_cancelamento_texto')->nullable();
            // PROMOTOR | LOJA | EMPRESA | OUTRO — quem "causou" a visita não acontecer.
            $table->string('responsavel_nao_execucao', 20)->nullable();
        });

        // Histórico append-only (molde de contrato_historicos / visita_intervencoes).
        Schema::create('ordem_servico_historicos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('acao', 30);
            $table->jsonb('dados')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_servico_historicos');

        Schema::table('ordens_servico', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('motivo_cancelamento_id');
            $table->dropConstrainedForeignId('cancelada_por_id');
            $table->dropColumn(['cancelada_em', 'motivo_cancelamento_texto', 'responsavel_nao_execucao']);
        });
    }
};
