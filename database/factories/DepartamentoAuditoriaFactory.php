<?php

namespace Database\Factories;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartamentoAuditoria>
 */
class DepartamentoAuditoriaFactory extends Factory
{
    protected $model = DepartamentoAuditoria::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->words(2, true),
            'ativo' => true,
        ];
    }
}
