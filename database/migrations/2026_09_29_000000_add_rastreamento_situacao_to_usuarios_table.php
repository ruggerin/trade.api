<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Situação do rastreamento informada pelo próprio app do promotor (docs/47 §5.4) — por
        // que ele está ou não rastreando (ATIVO, SO_DURANTE_USO, GPS_DESLIGADO...). Separado de
        // ultima_localizacao_*: o caso que interessa é justamente quando NÃO chega posição.
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->string('rastreamento_situacao', 40)->nullable()->after('ultima_localizacao_em');
            $table->string('rastreamento_situacao_detalhe')->nullable()->after('rastreamento_situacao');
            $table->timestamp('rastreamento_situacao_em')->nullable()->after('rastreamento_situacao_detalhe');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn(['rastreamento_situacao', 'rastreamento_situacao_detalhe', 'rastreamento_situacao_em']);
        });
    }
};
