<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log de alterações do contrato (append-only, nunca editado) — sem empresa_id próprio,
        // isolamento herdado de contrato_id (mesmo padrão de CampanhaItem/ContratoMeta). Tem
        // uuid (convenção da API: todo id exposto em JSON é uuid, nunca o bigint interno — ver
        // docs/02-API-BACKEND.md#convenção-de-identificadores-na-api) mesmo sem endpoint próprio
        // de show/update/delete por linha, só a listagem. `descricao` já vem pronta (texto
        // humano montado no controller ao detectar o que mudou), não é um diff estruturado —
        // mais simples de gravar e de exibir, ver
        // App\Http\Controllers\ContratoController::registrarHistorico.
        Schema::create('contrato_historicos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            // Nullable: nada tecnicamente impede uma linha sem usuário (ex.: ação de sistema no
            // futuro), mas hoje sempre vem preenchido — toda escrita em Contrato exige
            // autenticação.
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->string('descricao');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_historicos');
    }
};
