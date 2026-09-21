<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca e código externo (do ERP de origem) como atributos do produto — ver
 * docs/27-BUSCA-MULTIPLA-DE-PRODUTOS.md §2 decisões 3 e 4. Aditiva e nullable: produto existente
 * nasce sem marca/código externo até alguém editar. Sem UNIQUE, mesmo raciocínio do código de
 * barras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->foreignId('marca_id')->nullable()->after('secao_id')->constrained('marcas_auditoria')->nullOnDelete();
            $table->string('codigo_externo', 64)->nullable()->after('codigo_barras');
            $table->index(['empresa_id', 'codigo_externo']);
        });
    }

    public function down(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'codigo_externo']);
            $table->dropConstrainedForeignId('marca_id');
            $table->dropColumn('codigo_externo');
        });
    }
};
