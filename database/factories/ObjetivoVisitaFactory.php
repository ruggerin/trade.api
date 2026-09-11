<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\ObjetivoVisita;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObjetivoVisita>
 */
class ObjetivoVisitaFactory extends Factory
{
    protected $model = ObjetivoVisita::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => 'Objetivo '.$this->faker->unique()->word(),
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
