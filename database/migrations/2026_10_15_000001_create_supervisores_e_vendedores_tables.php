<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Espelho do ERP (docs/73 Fase 1): vendedor (RCA) e supervisor são cadastros próprios, fora de
        // `usuarios` — muitos nunca logam e não podem consumir licença. `codigo_externo` é a chave do ERP.
        Schema::create('supervisores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('codigo_externo', 64);
            $table->string('nome');
            $table->string('telefone', 30)->nullable();
            $table->string('email')->nullable();
            // Quando o supervisor também usa o Horus (login de GESTOR, por exemplo).
            $table->foreignId('usuario_id')->nullable()->unique()->constrained('usuarios')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo_externo']);
        });

        Schema::create('vendedores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('codigo_externo', 64);
            $table->string('nome');
            $table->string('telefone', 30)->nullable();
            $table->string('email')->nullable();
            // `codsupervisor` cru do ERP: o vínculo (supervisor_id) é resolvido a partir dele, e
            // refeito quando o supervisor chega depois do vendedor.
            $table->string('codigo_supervisor', 64)->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('supervisores')->nullOnDelete();
            // Vendedor que também usa o app (modo Vendedor, docs/38).
            $table->foreignId('usuario_id')->nullable()->unique()->constrained('usuarios')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo_externo']);
            $table->index(['empresa_id', 'codigo_supervisor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendedores');
        Schema::dropIfExists('supervisores');
    }
};
