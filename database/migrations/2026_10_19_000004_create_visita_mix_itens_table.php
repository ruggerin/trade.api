<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // docs/90 §3.1: o mix da loja copiado no check-out, sempre no nível do produto. Descrição,
    // marca, seção e departamento são cópia proposital: catálogo reclassificado depois não muda
    // como a visita antiga aparece.
    public function up(): void
    {
        Schema::create('visita_mix_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->foreignId('produto_id')->nullable()->constrained('produtos_auditoria')->nullOnDelete();
            $table->string('descricao');
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria')->nullOnDelete();
            $table->string('marca_descricao')->nullable();
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria')->nullOnDelete();
            $table->string('secao_descricao')->nullable();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria')->nullOnDelete();
            $table->string('departamento_descricao')->nullable();
            $table->string('propriedade', 20)->nullable();
            $table->boolean('produto_chave')->default(false);
            // Como o produto entrou no mix: cadastrado como produto, seção, departamento ou marca.
            $table->string('origem_tipo', 20);
            // O promotor adicionou ao mix durante esta visita (doc 14 §8).
            $table->boolean('adicionado_na_visita')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->unique(['visita_id', 'produto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_mix_itens');
    }
};
