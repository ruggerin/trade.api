<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Módulo pago Pedido de Venda (docs/38-PEDIDO-VENDEDOR.md §12) — só o SUPERADMIN liga,
        // mesmo lugar de plano/limites. Default false: nenhuma empresa ganha o módulo sem contratar.
        Schema::table('empresas', function (Blueprint $table): void {
            $table->boolean('pedidos_venda_habilitado')->default(false)->after('limite_licencas');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn('pedidos_venda_habilitado');
        });
    }
};
