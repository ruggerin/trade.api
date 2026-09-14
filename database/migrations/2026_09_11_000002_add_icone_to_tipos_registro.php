<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            // Slug do Material Design Icons (pictogrammers.com/library/mdi), sem o prefixo
            // "mdi-" (normalizado em App\Support\IconeTipoRegistro::normalizar) — o mesmo slug
            // renderiza nos dois apps: admin via @mdi/font (classe CSS `mdi mdi-{slug}`), mobile
            // via @expo/vector-icons MaterialCommunityIcons (prop `name={slug}`), sem precisar
            // de asset próprio nem tradução entre plataformas. Ver
            // docs/03-ADMIN-WEB.md#tipos-de-registro.
            $table->string('icone', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->dropColumn('icone');
        });
    }
};
