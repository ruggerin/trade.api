<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\NivelExibicao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NivelExibicao>
 */
class NivelExibicaoFactory extends Factory
{
    protected $model = NivelExibicao::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->word(),
            'ativo' => true,
        ];
    }
}
