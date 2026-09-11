<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable — PDVs já cadastrados não têm esse dado, e o cadastro no admin não vai virar
        // bloqueante retroativo por causa disso. Único por empresa (não globalmente, diferente
        // de empresas.cnpj) — mesmo padrão de unicidade escopada por tenant usado em
        // parametros.chave, ver docs/01-MODELO-DE-DADOS.md.
        Schema::table('pontos_venda', function (Blueprint $table): void {
            $table->string('cnpj', 20)->nullable()->after('codigo_externo');
            $table->unique(['empresa_id', 'cnpj']);
        });
    }

    public function down(): void
    {
        Schema::table('pontos_venda', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'cnpj']);
            $table->dropColumn('cnpj');
        });
    }
};
