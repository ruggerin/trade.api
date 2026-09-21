<?php

namespace App\Console\Commands;

use App\Enums\OrigemOrdemServico;
use App\Enums\RecorrenciaAgendaVisita;
use App\Enums\StatusOrdemServico;
use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Geração automática de OrdemServico a partir da agenda de visita do promotor — mesmo desenho
 * de GerarOrdensServicoPorCampanha, trocando "campanha + qualquer PDV ativo" por "regra de
 * agenda + PDV/promotor específicos, filtrado por dia da semana ou data". Ver
 * docs/10-AGENDA-VISITA.md.
 */
class GerarOrdensServicoPorAgenda extends Command
{
    protected $signature = 'ordens-servico:gerar-por-agenda {agenda? : uuid de UMA agenda — usado ao salvar, pra não esperar o agendador do dia seguinte}';

    protected $description = 'Gera OrdemServico pendente para as agendas de visita ativas que batem com o dia de hoje';

    public function handle(): int
    {
        $totalCriadas = 0;
        $hoje = now()->startOfDay();

        Empresa::withoutGlobalScopes()->where('ativo', true)->each(function (Empresa $empresa) use (&$totalCriadas, $hoje): void {
            $agendas = AgendaVisita::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->when($this->argument('agenda'), fn ($q, $uuid) => $q->where('uuid', $uuid))
                ->where(function ($query) use ($hoje) {
                    $query
                        ->where(fn ($q) => $q->where('recorrencia', RecorrenciaAgendaVisita::SEMANAL)->where('dia_semana', $hoje->dayOfWeek))
                        ->orWhere(fn ($q) => $q->where('recorrencia', RecorrenciaAgendaVisita::DATA_UNICA)->whereDate('data', $hoje));
                })
                ->get();

            foreach ($agendas as $agenda) {
                if ($this->criarSeDevido($empresa, $agenda, $hoje)) {
                    $totalCriadas++;
                }
            }
        });

        $this->info("Ordens de serviço criadas: {$totalCriadas}.");

        return self::SUCCESS;
    }

    private function criarSeDevido(Empresa $empresa, AgendaVisita $agenda, Carbon $hoje): bool
    {
        // Nunca duplica: já existe uma OS pendente/em andamento criada hoje pra esta agenda.
        $jaCriadaHoje = OrdemServico::withoutGlobalScopes()
            ->where('agenda_visita_id', $agenda->id)
            ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])
            ->whereDate('prazo_inicio', $hoje)
            ->exists();

        if ($jaCriadaHoje) {
            return false;
        }

        OrdemServico::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $agenda->ponto_venda_id,
            'usuario_id' => $agenda->usuario_id,
            'origem' => OrigemOrdemServico::AGENDA,
            'agenda_visita_id' => $agenda->id,
            'tipo_visita_id' => $agenda->tipo_visita_id,
            'objetivo_visita_id' => $agenda->objetivo_visita_id,
            'prioridade' => $agenda->prioridade,
            'horario_previsto' => $agenda->horario_previsto,
            'obrigatoria' => $agenda->obrigatoria,
            'prazo_inicio' => $hoje->copy(),
            'prazo_fim' => $hoje->copy()->endOfDay(),
            'status' => StatusOrdemServico::PENDENTE,
            'observacao' => $agenda->observacao,
        ]);

        // DATA_UNICA já cumpriu seu papel — se auto-desativa pra não tentar de novo amanhã e
        // não acumular lixo de regras pontuais já usadas. Ver docs/10-AGENDA-VISITA.md, decisão 4.
        if ($agenda->recorrencia === RecorrenciaAgendaVisita::DATA_UNICA) {
            $agenda->update(['ativo' => false]);
        }

        return true;
    }
}
