<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/100: "Cada seção / linha" vira lista guiada (nível + origem: Mix da loja ou lista fixa) e o
 * checklist de produtos ganha o formato "conferir item a item" (Tem / Não tem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            // SECAO | DEPARTAMENTO | MARCA — nulo = "Cada seção / linha" livre (o promotor vincula).
            $table->string('linha_nivel', 15)->nullable();
            // MIX_LOJA (o que a loja tem do nível) | FIXA (a lista abaixo, em toda loja).
            $table->string('linha_origem', 10)->default('MIX_LOJA');
            // Uuids do nível escolhido, na ordem — só com linha_origem = FIXA.
            $table->json('linhas_fixas')->nullable();
        });

        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            // PRESENTE (nasce tudo presente, desmarca o que falta) | CONFERIR (Tem / Não tem, item a item).
            $table->string('sortimento_modo', 10)->default('PRESENTE');
        });
    }

    public function down(): void
    {
        Schema::table('campos_tipo_registro', fn (Blueprint $t) => $t->dropColumn('sortimento_modo'));
        Schema::table('tipos_registro', fn (Blueprint $t) => $t->dropColumn(['linha_nivel', 'linha_origem', 'linhas_fixas']));
    }
};
