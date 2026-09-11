<?php

namespace Database\Factories;

use App\Models\CentroCusto;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CentroCusto>
 */
class CentroCustoFactory extends Factory
{
    protected $model = CentroCusto::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => 'Centro de custo '.$this->faker->unique()->word(),
            'carga_horaria_semanal' => 44,
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
