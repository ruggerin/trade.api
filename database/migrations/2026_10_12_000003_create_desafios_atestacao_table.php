<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/67 Fase 3 — desafio (nonce) de uso único que o app passa pra Play Integrity / App Attest,
 * amarrado à chave pública do aparelho: a atestação só vale pra aquele login e aquela chave.
 * Apagados pelo sessoes:limpar depois de vencidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desafios_atestacao', function (Blueprint $table): void {
            $table->id();
            $table->string('desafio', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desafios_atestacao');
    }
};
