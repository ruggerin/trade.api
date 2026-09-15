<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\RamoAtividade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RamoAtividade>
 */
class RamoAtividadeFactory extends Factory
{
    protected $model = RamoAtividade::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->word(),
            'ativo' => true,
        ];
    }
}
