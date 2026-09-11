<?php

namespace Database\Factories;

use App\Enums\Propriedade;
use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProdutoAuditoria>
 */
class ProdutoAuditoriaFactory extends Factory
{
    protected $model = ProdutoAuditoria::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'descricao' => $this->faker->unique()->words(3, true),
            'propriedade' => Propriedade::PROPRIA,
            'ativo' => true,
        ];
    }
}
