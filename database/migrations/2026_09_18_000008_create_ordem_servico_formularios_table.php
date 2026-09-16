<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A peça que dá o controle de progresso ("N expedidos, M preenchidos") — uma linha por
        // formulário exigido numa OS específica. Preenchida automaticamente (copiada de
        // direcionamento_formularios) quando a OS nasce de um Direcionamento, ou diretamente
        // pelo gestor numa OS manual avulsa — ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §7.2.
        Schema::create('ordem_servico_formularios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico')->cascadeOnDelete();
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->boolean('obrigatorio')->default(true);
            $table->boolean('calcula_percentual_compliance')->default(false);
            // Preenchido quando o promotor cria, dentro da visita vinculada a esta OS, um
            // VisitaRegistro cujo tipo_registro_id bate com esta linha — ver
            // VisitaRegistroController::store.
            $table->timestamp('respondido_em')->nullable();
            $table->timestamps();
            $table->unique(['ordem_servico_id', 'tipo_registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_servico_formularios');
    }
};
