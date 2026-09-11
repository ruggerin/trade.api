<?php

use App\Enums\AcaoIntervencaoVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log append-only de intervenção administrativa em Visita (cancelar / forçar checkout /
        // corrigir horário) — nunca editado. Sem empresa_id próprio, isolamento herdado de
        // visita_id (mesmo padrão de contrato_historicos). Tem uuid (convenção da API) mesmo sem
        // endpoint de show/update por linha — só vem junto no GET da visita. Ver
        // docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
        Schema::create('visita_intervencoes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            // Nullable por consistência com contrato_historicos, mas sempre preenchido — toda
            // intervenção exige autenticação.
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->enum('acao', array_column(AcaoIntervencaoVisita::cases(), 'value'));
            $table->text('motivo');
            // Frase pronta pra exibição, montada no controller (mesma ideia de
            // contrato_historicos.descricao).
            $table->string('descricao');
            // Snapshots { status, inicio_data, fim_data, checkout_tipo } antes/depois — a
            // descricao cobre a exibição, os snapshots servem pra rastreabilidade fina e pra
            // suportar várias intervenções na mesma visita (o "anterior" da 2ª não é o estado
            // original, é o resultado da 1ª).
            $table->jsonb('valores_anteriores');
            $table->jsonb('valores_novos');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_intervencoes');
    }
};
