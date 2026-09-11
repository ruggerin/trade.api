<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log de login (append-only, nunca editado) — diferente de `dispositivos`, que só guarda
        // o último acesso (é sobrescrito a cada login). Alimenta o histórico de eventos do
        // usuário no admin web, ver docs/02-API-BACKEND.md e UsuarioController::historico. Sem
        // uuid/empresa_id próprios — pivot derivada de usuario_id, mesmo padrão de
        // promotor_pontos_venda.
        Schema::create('usuario_login_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('dispositivo_identificador')->nullable();
            $table->string('dispositivo_nome')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_login_logs');
    }
};
