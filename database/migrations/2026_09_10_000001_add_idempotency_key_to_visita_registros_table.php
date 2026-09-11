<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência de registro (foto/ruptura/observação) — mesmo raciocínio de
 * 2026_09_08_000018_add_idempotency_key_to_visitas_table.php, aplicado ao envio de registros. O
 * app mobile manda o id local do registro na fila (uuid gerado no dispositivo,
 * `fila_registros.id`) como `idempotency_key`; se o app fechar entre o servidor confirmar e o
 * celular gravar a resposta, o reenvio automático da fila cai aqui de novo com a MESMA chave — o
 * controller devolve o registro já criado (com a mesma foto) em vez de duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('visita_id');
        });
        // Único por visita (não global) — registro não é tenant-aware por conta própria, e
        // escopar por visita_id evita qualquer colisão teórica entre visitas diferentes sem
        // precisar reproduzir a checagem de ownership que o check-in faz.
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->unique(['visita_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropUnique(['visita_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
