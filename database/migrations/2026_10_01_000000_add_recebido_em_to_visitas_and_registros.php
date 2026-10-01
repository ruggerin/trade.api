<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o servidor RECEBEU cada passo — docs/51-ENVIO-DA-FILA-EM-TEMPO-REAL.md Fase 2. A hora do
 * campo (`inicio_data`, `fim_data`, `created_at` do registro) passa a vir do app; isto guarda a
 * referência do servidor, pra medir o atraso de envio e mostrar "chegou X min depois" no admin.
 * Nulo = registro de antes desta versão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->timestamp('checkin_recebido_em')->nullable();
            $table->timestamp('checkout_recebido_em')->nullable();
        });

        Schema::table('visita_registros', function (Blueprint $table) {
            $table->timestamp('recebido_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->dropColumn(['checkin_recebido_em', 'checkout_recebido_em']);
        });

        Schema::table('visita_registros', function (Blueprint $table) {
            $table->dropColumn('recebido_em');
        });
    }
};
