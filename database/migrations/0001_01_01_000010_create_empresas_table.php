<?php

use App\Enums\PlanoEmpresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defensivo: gen_random_uuid() já é built-in a partir do Postgres 13, mas garantimos
        // a extensão pra não depender da versão exata do Postgres local.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        Schema::create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('razao_social');
            $table->string('nome_fantasia');
            $table->string('cnpj', 20)->unique();
            $table->enum('plano', array_column(PlanoEmpresa::cases(), 'value'))
                ->default(PlanoEmpresa::GRATUITO->value);
            $table->unsignedInteger('limite_usuarios')->nullable();
            $table->unsignedInteger('limite_pontos_venda')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
