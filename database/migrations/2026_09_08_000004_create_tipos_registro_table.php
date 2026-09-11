<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Substitui o antigo enum fixo TipoRegistroVisita (FOTO/RUPTURA/OBSERVACAO) — cada
        // empresa cadastra e customiza os próprios tipos de registro (ex.: "Ação da
        // concorrência", "Ponto extra"), mesmo espírito de NivelExibicao. Ver
        // docs/01-MODELO-DE-DADOS.md.
        Schema::create('tipos_registro', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('descricao');
            $table->boolean('exige_foto')->default(false);
            // Permite vincular o registro a um produto/seção/departamento/marca do catálogo
            // (ver campos tipo_vinculo/secao_id/departamento_id/marca_id em visita_registros) —
            // nem todo tipo faz sentido vincular (ex.: uma observação solta sobre a loja).
            $table->boolean('permite_vincular_catalogo')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_registro');
    }
};
