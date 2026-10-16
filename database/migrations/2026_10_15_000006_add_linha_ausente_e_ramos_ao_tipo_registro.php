<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Escopo de Ação LINHA_AUSENTE (docs/80 §5.1): a Ação vale onde nenhum item do Mix cobre
        // este recorte. Mesmo desenho das colunas sortimento_* de campos_tipo_registro; tudo NULL
        // em qualquer outro escopo (o controller limpa).
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->string('escopo_tipo_vinculo', 20)->nullable()->after('escopo_acao');
            $table->foreignId('escopo_secao_id')->nullable()->after('escopo_tipo_vinculo')->constrained('secoes_auditoria')->nullOnDelete();
            $table->foreignId('escopo_departamento_id')->nullable()->after('escopo_secao_id')->constrained('departamentos_auditoria')->nullOnDelete();
            $table->foreignId('escopo_marca_id')->nullable()->after('escopo_departamento_id')->constrained('marcas_auditoria')->nullOnDelete();
        });

        // Filtro por ramo de atividade da loja (docs/80 §5.4) — vale pra qualquer escopo de Ação.
        // Vazio = todos os ramos. Isolamento herdado do TipoRegistro, como as pivôs de LOJA_REDE.
        Schema::create('tipo_registro_ramos_atividade', function (Blueprint $table): void {
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('ramo_atividade_id')->constrained('ramos_atividade')->cascadeOnDelete();
            $table->primary(['tipo_registro_id', 'ramo_atividade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_registro_ramos_atividade');

        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('escopo_marca_id');
            $table->dropConstrainedForeignId('escopo_departamento_id');
            $table->dropConstrainedForeignId('escopo_secao_id');
            $table->dropColumn('escopo_tipo_vinculo');
        });
    }
};
