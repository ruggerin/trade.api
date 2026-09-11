<?php

namespace Database\Factories;

use App\Models\CampanhaAuditoria;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampanhaAuditoria>
 */
class CampanhaAuditoriaFactory extends Factory
{
    protected $model = CampanhaAuditoria::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->words(3, true),
            'vigencia_inicio' => now()->subDay(),
            'vigencia_fim' => now()->addMonth(),
            'ativo' => true,
        ];
    }

    public function expirada(): static
    {
        return $this->state([
            'vigencia_inicio' => now()->subMonths(2),
            'vigencia_fim' => now()->subMonth(),
        ]);
    }

    public function futura(): static
    {
        return $this->state([
            'vigencia_inicio' => now()->addMonth(),
            'vigencia_fim' => now()->addMonths(2),
        ]);
    }

    public function inativa(): static
    {
        return $this->state(['ativo' => false]);
    }

    // execucao_recorrente já é default(true) no schema, mas sem frequencia_dias (nullable, sem
    // default) o comando de geração automática ignora a campanha — ver
    // App\Console\Commands\GerarOrdensServicoPorCampanha.
    public function recorrente(int $frequenciaDias = 15): static
    {
        return $this->state(['execucao_recorrente' => true, 'frequencia_dias' => $frequenciaDias]);
    }
}
