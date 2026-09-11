<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de barras (EAN/UPC/SKU) do produto — opcional por padrão, mas pode virar obrigatório
 * e/ou único no cadastro conforme os parâmetros CODIGO_BARRAS_OBRIGATORIO/CODIGO_BARRAS_UNICO
 * da empresa (ver App\Support\CodigoBarrasProduto). Sem constraint UNIQUE no banco de propósito
 * — a unicidade é parametrizável por empresa (liga/desliga em runtime), não dá pra expressar
 * isso como constraint fixa de schema; é validada na camada de FormRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->string('codigo_barras', 64)->nullable()->after('descricao');
            $table->index(['empresa_id', 'codigo_barras']);
        });
    }

    public function down(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'codigo_barras']);
            $table->dropColumn('codigo_barras');
        });
    }
};
