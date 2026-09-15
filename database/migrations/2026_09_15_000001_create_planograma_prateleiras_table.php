<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sem empresa_id próprio — tenant resolvido via planograma_id (mesmo padrão de
        // campos_tipo_registro em relação a tipos_registro). Ver docs/22-PLANOGRAMA.md.
        Schema::create('planograma_prateleiras', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('planograma_id')->constrained('planogramas')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('descricao')->nullable();
            // Quantidade de posições (blocos) disponíveis nesta prateleira — ver
            // planograma_blocos.posicao_inicio/largura.
            $table->unsignedSmallInteger('quantidade_blocos');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planograma_prateleiras');
    }
};
