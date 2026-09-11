<?php

use App\Enums\PrioridadeVisita;
use App\Enums\RecorrenciaAgendaVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendas_visita', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            // Diferente de OrdemServico manual, aqui não existe fila aberta — é literalmente a
            // agenda pessoal de um promotor. Ver docs/10-AGENDA-VISITA.md, decisão 1.
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('tipo_visita_id')->nullable()->constrained('tipos_visita');
            $table->enum('prioridade', array_column(PrioridadeVisita::cases(), 'value'))
                ->default(PrioridadeVisita::MEDIA->value);
            $table->enum('recorrencia', array_column(RecorrenciaAgendaVisita::cases(), 'value'));
            // 0 (domingo) a 6 (sábado) — obrigatório quando recorrencia = SEMANAL.
            $table->unsignedTinyInteger('dia_semana')->nullable();
            // Obrigatório quando recorrencia = DATA_UNICA.
            $table->date('data')->nullable();
            // Opcional — informativo, não vira janela rígida na OS gerada (ver decisão 3).
            $table->time('horario_previsto')->nullable();
            $table->boolean('obrigatoria')->default(true);
            // Pausar a regra sem apagar (ex. promotor de férias); DATA_UNICA se auto-desativa
            // sozinha depois de gerar (ver decisão 4).
            $table->boolean('ativo')->default(true);
            $table->text('observacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendas_visita');
    }
};
