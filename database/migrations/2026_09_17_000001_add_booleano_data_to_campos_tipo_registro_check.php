<?php

use App\Enums\TipoCampoRegistro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O CHECK de "tipo_campo" foi criado só com os 4 valores originais (ver
 * 2026_09_08_000005_create_campos_tipo_registro_table.php) — precisa recriar pra aceitar
 * BOOLEANO/DATA (docs/20-FORMULARIO-DINAMICO-CAMPANHA.md Fase 1). Mesmo padrão de
 * 2026_09_09_000003_add_prazo_proposto_and_autonomia_status_to_ordens_servico.php.
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
        DB::statement("ALTER TABLE campos_tipo_registro ADD CONSTRAINT campos_tipo_registro_tipo_campo_check CHECK (tipo_campo IN ('NUMERO', 'TEXTO', 'MOEDA', 'MULTIPLA_ESCOLHA'))");
    }
};
