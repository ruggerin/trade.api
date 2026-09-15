<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Planograma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Planograma>
 */
class PlanogramaFactory extends Factory
{
    protected $model = Planograma::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->words(3, true),
            'ativo' => true,
        ];
    }
}
