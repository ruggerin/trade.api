<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promotor escolhe marca/departamento/seção no início do formulário (docs/79 §4.1). Três
        // booleans em vez de um enum: as 7 combinações virariam um enum difícil de ler. Todos
        // false = comportamento atual.
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->boolean('escolhe_marca')->default(false)->after('granularidade_padrao');
            $table->boolean('escolhe_departamento')->default(false)->after('escolhe_marca');
            $table->boolean('escolhe_secao')->default(false)->after('escolhe_departamento');
        });

        // Checklist de Mix que segue o recorte escolhido pelo promotor — boolean à parte porque
        // sortimento_tipo_vinculo usa o enum de campanha_itens, onde "escolha do promotor" não cabe.
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->boolean('sortimento_segue_escolha')->default(false)->after('sortimento_marca_id');
        });
    }

    public function down(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->dropColumn('sortimento_segue_escolha');
        });

        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn(['escolhe_marca', 'escolhe_departamento', 'escolhe_secao']);
        });
    }
};
