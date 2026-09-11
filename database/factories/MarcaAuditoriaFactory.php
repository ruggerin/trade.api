<?php

namespace Database\Factories;

use App\Enums\Propriedade;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarcaAuditoria>
 */
class MarcaAuditoriaFactory extends Factory
{
    protected $model = MarcaAuditoria::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->company(),
            'propriedade' => Propriedade::PROPRIA,
            'ativo' => true,
        ];
    }
}
