<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código curto que um ADMIN/GESTOR (com visitas.intervir) gera no admin web e dita por
        // telefone pro promotor, em vez de digitar e-mail e senha no aparelho dele — ver
        // App\Http\Controllers\AutorizacaoGestorController e
        // docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md §12. Vida curta (10 min) e uso único
        // (usado_em), por isso guardado em texto puro (não é uma credencial de login de verdade,
        // é um segredo efêmero — o mesmo raciocínio de um código de SMS/OTP).
        Schema::create('autorizacoes_gestor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('codigo', 6);
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->timestamps();

            // Toda checagem de código filtra por estes três campos juntos (empresa + código +
            // ainda não usado) — ver AutorizacaoGestor::valida().
            $table->index(['empresa_id', 'codigo', 'usado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizacoes_gestor');
    }
};
