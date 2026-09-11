<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\TipoVisita;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoVisita>
 */
class TipoVisitaFactory extends Factory
{
    protected $model = TipoVisita::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => 'Tipo '.$this->faker->unique()->word(),
            'cor' => $this->faker->hexColor(),
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
