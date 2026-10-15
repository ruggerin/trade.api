<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exceção pontual autorizada pelo gestor pra quem tem o perfil obrigado a seguir a agenda
        // (docs/74): libera UMA visita numa loja, fora da agenda e da sequência. O gestor autoriza
        // com o app online; o check-in, que pode sair offline, leva o uuid e consome a liberação
        // (usada_em + visita_id). Fica como rastro de quem autorizou, quando e por quê.
        Schema::create('liberacoes_visita', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda')->cascadeOnDelete();
            $table->foreignId('autorizada_por_id')->constrained('usuarios');
            $table->string('motivo', 500)->nullable();
            $table->timestamp('expira_em');
            $table->timestamp('usada_em')->nullable();
            $table->foreignId('visita_id')->nullable()->constrained('visitas')->nullOnDelete();
            $table->timestamps();

            $table->index(['usuario_id', 'ponto_venda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberacoes_visita');
    }
};
