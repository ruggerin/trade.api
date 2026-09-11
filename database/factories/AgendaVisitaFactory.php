<?php

namespace Database\Factories;

use App\Enums\PrioridadeVisita;
use App\Enums\RecorrenciaAgendaVisita;
use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgendaVisita>
 */
class AgendaVisitaFactory extends Factory
{
    protected $model = AgendaVisita::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'ponto_venda_id' => PontoVenda::factory(),
            'usuario_id' => Usuario::factory()->promotor(),
            'tipo_visita_id' => null,
            'prioridade' => PrioridadeVisita::MEDIA,
            'recorrencia' => RecorrenciaAgendaVisita::SEMANAL,
            'dia_semana' => 1,
            'data' => null,
            'horario_previsto' => null,
            'obrigatoria' => true,
            'ativo' => true,
            'observacao' => null,
        ];
    }

    public function semanal(int $diaSemana): static
    {
        return $this->state([
            'recorrencia' => RecorrenciaAgendaVisita::SEMANAL,
            'dia_semana' => $diaSemana,
            'data' => null,
        ]);
    }

    public function dataUnica(string $data): static
    {
        return $this->state([
            'recorrencia' => RecorrenciaAgendaVisita::DATA_UNICA,
            'dia_semana' => null,
            'data' => $data,
        ]);
    }

    public function inativa(): static
    {
        return $this->state(['ativo' => false]);
    }
}
