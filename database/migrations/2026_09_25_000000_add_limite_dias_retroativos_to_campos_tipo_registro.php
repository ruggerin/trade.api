<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limite opcional de quantos dias no passado um campo DATA aceita — docs/35-LIMITE-RETROATIVO-
 * CAMPO-DATA.md. `null` = sem limite (comportamento atual, preservado pra todo campo já
 * cadastrado). Só tem efeito prático quando `tipo_campo = DATA`, mas não travado por CHECK
 * constraint (mesmo raciocínio dos campos de sortimento, que só valem pra SORTIMENTO e ficam
 * NULL pros demais tipos sem trava no banco).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->unsignedInteger('limite_dias_retroativos')->nullable()->after('obrigatorio');
        });
    }

    public function down(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->dropColumn('limite_dias_retroativos');
        });
    }
};
