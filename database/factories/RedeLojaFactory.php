<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\RedeLoja;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedeLoja>
 */
class RedeLojaFactory extends Factory
{
    protected $model = RedeLoja::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->company(),
            'ativo' => true,
        ];
    }
}
