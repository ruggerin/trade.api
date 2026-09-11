<?php

use App\Enums\Propriedade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos_auditoria', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('descricao');
            $table->text('imagem_url')->nullable();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria');
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria');
            $table->string('nivel_exibicao')->nullable();
            $table->boolean('produto_final')->default(false);
            // Quando true, o produto representa uma combinação genérica "seção × marca" em vez
            // de um SKU específico — ver docs/01-MODELO-DE-DADOS.md.
            $table->boolean('gerar_via_secoes_marcas')->default(false);
            $table->double('peso_kg')->nullable();
            $table->enum('propriedade', array_column(Propriedade::cases(), 'value'));
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos_auditoria');
    }
};
