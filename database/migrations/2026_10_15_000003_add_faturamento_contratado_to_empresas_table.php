<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Faturamento (docs/61) é recurso negociado na adesão do plano: o SUPERADMIN libera aqui, e só
        // então o ADMIN da empresa consegue ligar o parâmetro FATURAMENTO_HABILITADO. Mesmo molde de
        // pedidos_venda_habilitado (docs/38 §12).
        Schema::table('empresas', function (Blueprint $table): void {
            $table->boolean('faturamento_contratado')->default(false)->after('pedidos_venda_habilitado');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn('faturamento_contratado');
        });
    }
};
