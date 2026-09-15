<?php

use App\Enums\TipoCampoRegistro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mesma pegadinha documentada em 2026_09_17_000001 (ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md
 * §4.1) — o CHECK de "tipo_campo" precisa ser recriado toda vez que um case novo entra no enum
 * PHP, senão o INSERT falha com SQLSTATE[23514] num banco que já existia antes do enum crescer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE campos_tipo_registro DROP CONSTRAINT campos_tipo_registro_tipo_campo_check');
        $valores = "'".implode("', '", array_column(TipoCampoRegistro::cases(), 'value'))."'";
        DB::statement("ALTER TABLE campos_tipo_registro ADD CONSTRAINT campos_tipo_registro_tipo_campo_check CHECK (tipo_campo IN ({$valores}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campos_tipo_registro DROP CONSTRAINT campos_tipo_registro_tipo_campo_check');
        DB::statement("ALTER TABLE campos_tipo_registro ADD CONSTRAINT campos_tipo_registro_tipo_campo_check CHECK (tipo_campo IN ('NUMERO', 'TEXTO', 'MOEDA', 'MULTIPLA_ESCOLHA', 'BOOLEANO', 'DATA'))");
    }
};
