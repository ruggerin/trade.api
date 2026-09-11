<?php

namespace Database\Factories;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\SecaoAuditoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecaoAuditoria>
 */
class SecaoAuditoriaFactory extends Factory
{
    protected $model = SecaoAuditoria::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'departamento_id' => DepartamentoAuditoria::factory(),
            'descricao' => $this->faker->unique()->words(2, true),
            'ativo' => true,
        ];
    }
}
