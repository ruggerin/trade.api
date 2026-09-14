<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // Caminho no disco privado (mesmo padrão de visita_registros.imagem_path) — separado
            // de `avatar_url` de propósito: `avatar_url` continua sendo o campo de texto livre
            // que o admin web já preenche manualmente (URL externa); `foto_path` é preenchido só
            // pelo upload feito pelo próprio usuário (self-service, ver AuthController::atualizarFoto).
            // UsuarioResource resolve `foto_url` a partir daqui, nunca mistura os dois.
            $table->string('foto_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('foto_path');
        });
    }
};
