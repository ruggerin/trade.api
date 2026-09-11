<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência de check-in — ver App\Http\Controllers\VisitaController::store e
 * docs/04-APP-MOBILE.md, "Fila offline de envio". O app mobile manda o id local da visita (uuid
 * gerado no dispositivo, `fila_visitas.id`) como `idempotency_key` no check-in; se o app fechar
 * bem entre o servidor confirmar e o celular gravar a resposta, o reenvio automático da fila cai
 * aqui de novo com a MESMA chave — o controller devolve a visita já criada em vez de duplicar.
 * `NULL` continua permitido (nunca colide entre si num unique index — Postgres não considera
 * NULL igual a NULL) pra não quebrar nada que ainda não manda a chave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique()->after('ordem_servico_id');
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
    }
};
