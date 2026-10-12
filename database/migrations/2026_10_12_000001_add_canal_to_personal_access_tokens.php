<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/67 §4.2/§4.4 — todo token nasce marcado com o canal (`web` = admin, `mobile` = app). As
 * rotas exclusivas do app só aceitam `mobile`, e só o token `mobile` expira e é rotacionado por
 * /auth/refresh. `rotacionado_em` mantém o token antigo por alguns segundos depois do refresh
 * (requisições em voo) e serve pra detectar cópia: reaproveitar um token já rotacionado derruba
 * as sessões mobile do usuário (App\Support\SessaoApp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('canal', 10)->nullable()->after('name');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('rotacionado_em')->nullable();
        });

        // Tokens de PROMOTOR já existentes só podem ter vindo do app (promotor não entra no admin
        // web): viram `mobile` com 7 dias de validade — o app novo renova sozinho; o antigo pede
        // login de novo uma vez. Os demais ficam com canal null (legado, sem trava) até o próximo
        // login, pra não derrubar ninguém no deploy.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\Usuario')
            ->whereIn('tokenable_id', DB::table('usuarios')->where('user_type', 'PROMOTOR')->select('id'))
            ->update(['canal' => 'mobile', 'expires_at' => now()->addDays(7)]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['canal', 'ip', 'user_agent', 'rotacionado_em']);
        });
    }
};
