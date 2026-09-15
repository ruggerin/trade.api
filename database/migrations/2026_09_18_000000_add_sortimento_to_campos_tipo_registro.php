<?php

use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoItemCampanha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo SORTIMENTO (docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 3) — checklist de produtos
 * presente/ausente. `sortimento_*` só é preenchido quando `tipo_campo = SORTIMENTO`; o resto
 * fica NULL pros outros tipos, mesmo padrão de `opcoes` (só usado por MULTIPLA_ESCOLHA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->enum('sortimento_origem', array_column(SortimentoOrigemCampo::cases(), 'value'))->nullable()->after('depende_de_valor');
            // Mesmo discriminador de TipoItemCampanha, mas sem o caso PRODUTO — um recorte de
            // produto único não faz sentido pra um checklist (ver App\Support\ResolverSortimentoCampo).
            $table->enum(
                'sortimento_tipo_vinculo',
                array_values(array_diff(array_column(TipoItemCampanha::cases(), 'value'), ['PRODUTO'])),
            )->nullable()->after('sortimento_origem');
            $table->foreignId('sortimento_secao_id')->nullable()->after('sortimento_tipo_vinculo')
                ->constrained('secoes_auditoria')->nullOnDelete();
            $table->foreignId('sortimento_departamento_id')->nullable()->after('sortimento_secao_id')
                ->constrained('departamentos_auditoria')->nullOnDelete();
            $table->foreignId('sortimento_marca_id')->nullable()->after('sortimento_departamento_id')
                ->constrained('marcas_auditoria')->nullOnDelete();
            // Ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 4 — ausência não vira ruptura
            // automaticamente, só quando este switch está ligado.
            $table->boolean('confirmar_ruptura_ausentes')->default(false)->after('sortimento_marca_id');
        });
    }

    public function down(): void
    {
        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sortimento_marca_id');
            $table->dropConstrainedForeignId('sortimento_departamento_id');
            $table->dropConstrainedForeignId('sortimento_secao_id');
            $table->dropColumn(['sortimento_origem', 'sortimento_tipo_vinculo', 'confirmar_ruptura_ausentes']);
        });
    }
};
