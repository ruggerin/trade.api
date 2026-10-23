<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notifications (docs/77 §3.2):
 * - `dispositivos.push_token`: token Expo do aparelho. Fica no vínculo, então aparelho desvinculado
 *   (`desvinculado_em`) deixa de receber sozinho.
 * - `push_recibos`: um ticket por mensagem aceita pela Expo, conferido depois por
 *   `push:conferir-recibos` pra apagar token de app desinstalado (DeviceNotRegistered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->string('push_token', 255)->nullable()->index();
            $table->timestamp('push_token_em')->nullable();
        });

        Schema::create('push_recibos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->string('ticket_id', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('conferido_em')->nullable();
            $table->index(['conferido_em', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_recibos');
        Schema::table('dispositivos', function (Blueprint $table): void {
            $table->dropIndex(['push_token']);
            $table->dropColumn(['push_token', 'push_token_em']);
        });
    }
};
