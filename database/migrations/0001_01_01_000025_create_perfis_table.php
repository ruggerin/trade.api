<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RBAC por empresa: cada empresa cria seus próprios perfis com um subconjunto do
        // catálogo fixo de permissões (App\Enums\Permissao — definido em código, só a
        // StoneUp adiciona uma permissão nova). Só tem efeito prático pra usuários
        // user_type=GESTOR — ADMIN sempre tem acesso total, ver EnsurePermissao.
        Schema::create('perfis', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome');
            $table->string('descricao')->nullable();
            // Lista pequena e fixa de chaves — pivot table seria over-engineering aqui;
            // jsonb do Postgres resolve bem (array de strings do enum Permissao).
            $table->jsonb('permissoes')->default('[]');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfis');
    }
};
