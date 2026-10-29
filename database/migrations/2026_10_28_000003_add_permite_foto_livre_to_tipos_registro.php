<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Área de foto livre ("Fotos"/"Outras fotos") no registro do app: a empresa pode desligar
        // por formulário e deixar só as perguntas FOTO (docs/98). Padrão ligado = como antes.
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->boolean('permite_foto_livre')->default(true)->after('permite_ruptura');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn('permite_foto_livre');
        });
    }
};
