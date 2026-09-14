<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sequência de exibição escolhida pelo gestor (admin e mobile passam a listar por ela
        // em vez de alfabética) — ver TipoRegistroController::index/store/mover e
        // docs/03-ADMIN-WEB.md#tipos-de-registro.
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordem')->default(0);
        });

        // Backfill: cravar a ordem alfabética atual (por descricao, dentro de cada empresa) —
        // sem isso, todo mundo nasceria com ordem=0 e a lista embaralharia sozinha no primeiro
        // deploy, antes de qualquer gestor mexer em "mover". Com isso, nada muda visualmente até
        // alguém reordenar de propósito.
        $empresas = DB::table('tipos_registro')->distinct()->pluck('empresa_id');
        foreach ($empresas as $empresaId) {
            $ids = DB::table('tipos_registro')
                ->where('empresa_id', $empresaId)
                ->orderBy('descricao')
                ->pluck('id');

            foreach ($ids as $indice => $id) {
                DB::table('tipos_registro')->where('id', $id)->update(['ordem' => $indice]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->dropColumn('ordem');
        });
    }
};
