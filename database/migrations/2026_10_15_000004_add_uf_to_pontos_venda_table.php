<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UF da loja (docs/78 §5). Nulável: as lojas que já existem ficam sem até alguém preencher
        // (reimportando com codigo_externo + uf). Sem dedução pela cidade — há cidades homônimas.
        Schema::table('pontos_venda', function (Blueprint $table): void {
            $table->char('uf', 2)->nullable()->after('cidade');
            $table->index(['empresa_id', 'uf']);
        });
    }

    public function down(): void
    {
        Schema::table('pontos_venda', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'uf']);
            $table->dropColumn('uf');
        });
    }
};
