<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista curada de produtos de um campo SORTIMENTO com `sortimento_origem = FIXO` — mesmo
 * raciocínio "tipo planograma" de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 3. Pivot pura
 * (id bigint, sem uuid/empresa_id), mesmo padrão de `promotor_pontos_venda`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campo_tipo_registro_produtos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campo_tipo_registro_id')->constrained('campos_tipo_registro')->cascadeOnDelete();
            $table->foreignId('produto_auditoria_id')->constrained('produtos_auditoria')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campo_tipo_registro_id', 'produto_auditoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campo_tipo_registro_produtos');
    }
};
