<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1:1 com usuarios — trava de 1 sessão ativa por PROMOTOR (ver AuthController::login).
        // Sem uuid nem empresa_id próprios: não tem endpoint dedicado, é suporte interno ao
        // login, mesma categoria de exceção que marcas_departamentos (ver
        // docs/01-MODELO-DE-DADOS.md#identificador-público-uuid).
        Schema::create('dispositivos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios');
            $table->string('identificador');
            $table->string('nome')->nullable();
            $table->timestamp('ultimo_acesso_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos');
    }
};
