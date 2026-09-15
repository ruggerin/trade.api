<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — um campo só
 * aparece (e só é obrigatório) quando outro campo do mesmo formulário tiver um valor específico.
 * Auto-referência dentro da mesma tabela: `depende_de_campo_id` aponta pra outro
 * `campos_tipo_registro` do mesmo `tipo_registro_id` (não validado por FK — a garantia de
 * "mesmo tipo" e "ordem anterior" é responsabilidade do StoreTipoRegistroRequest, ver §4.4 do
 * doc). `nullOnDelete`: apagar o campo pai (reordenação/edição do formulário) não deveria apagar
 * o campo filho, só soltar a dependência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->foreignId('depende_de_campo_id')->nullable()->after('ordem')
                ->constrained('campos_tipo_registro')->nullOnDelete();
            $table->string('depende_de_valor')->nullable()->after('depende_de_campo_id');
        });
    }

    public function down(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('depende_de_campo_id');
            $table->dropColumn('depende_de_valor');
        });
    }
};
