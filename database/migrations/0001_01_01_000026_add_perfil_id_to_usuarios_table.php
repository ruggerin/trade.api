<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            // Nullable: só GESTOR usa perfil na prática (validado na escrita, não aqui) — se
            // o perfil for apagado, o usuário simplesmente fica sem perfil (nullOnDelete),
            // não é motivo pra bloquear a exclusão do perfil.
            $table->foreignId('perfil_id')->nullable()->after('user_type')
                ->constrained('perfis')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('perfil_id');
        });
    }
};
