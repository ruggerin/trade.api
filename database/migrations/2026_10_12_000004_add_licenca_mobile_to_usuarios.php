<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/67 §3 (decisão de 12/10/2026): licença do app mobile atribuída pelo ADMIN, pra qualquer
 * user_type — só quem tem entra no app, e o limite de licenças conta quem tem (e está ativo).
 * `troca_aparelho_liberada_em`: o ADMIN liberou uma troca extra — o próximo aparelho novo entra
 * sem pedir aprovação e sem gastar a troca própria da janela (App\Support\VinculoAparelho).
 *
 * Quem já consumia licença (PROMOTOR ativo) nasce com ela, pra ninguém cair no deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->boolean('licenca_mobile')->default(false);
            $table->timestamp('troca_aparelho_liberada_em')->nullable();
        });

        DB::table('usuarios')->where('user_type', 'PROMOTOR')->update(['licenca_mobile' => true]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn(['licenca_mobile', 'troca_aparelho_liberada_em']);
        });
    }
};
