<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código do usuário no ERP/sistema de origem (ex.: RCA/codusur do vendedor) — mesmo papel
        // de pontos_venda.codigo_externo/produtos_auditoria.codigo_externo. Sem unicidade: o ERP
        // é quem manda nesse código, o PDV App só guarda e filtra.
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->string('codigo_externo', 64)->nullable()->after('email');
            $table->index(['empresa_id', 'codigo_externo']);
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'codigo_externo']);
            $table->dropColumn('codigo_externo');
        });
    }
};
