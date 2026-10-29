<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Botão "Produto em ruptura" no registro do app: a empresa pode desligar por formulário.
        // Padrão ligado = comportamento de antes.
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->boolean('permite_ruptura')->default(true)->after('filtra_marca');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn('permite_ruptura');
        });
    }
};
