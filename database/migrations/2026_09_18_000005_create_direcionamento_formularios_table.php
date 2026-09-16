<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // obrigatorio/calcula_percentual_compliance vivem AQUI, não em tipos_registro — o mesmo
        // formulário pode ser obrigatório num Direcionamento e opcional noutro, ver
        // docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 9.
        Schema::create('direcionamento_formularios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direcionamento_id')->constrained('direcionamentos')->cascadeOnDelete();
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->boolean('obrigatorio')->default(true);
            $table->boolean('calcula_percentual_compliance')->default(false);
            $table->timestamps();
            $table->unique(['direcionamento_id', 'tipo_registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcionamento_formularios');
    }
};
