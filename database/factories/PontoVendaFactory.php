<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\PontoVenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PontoVenda>
 */
class PontoVendaFactory extends Factory
{
    protected $model = PontoVenda::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'razao_social' => $this->faker->company().' LTDA',
            'fantasia' => $this->faker->company(),
            // Manaus, fixo (não randômico) — os testes de raio de check-in fazem contas exatas
            // em cima de latitude/longitude, não pode variar entre execuções.
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => $this->faker->streetAddress(),
            'bairro' => $this->faker->citySuffix(),
            'cidade' => 'Manaus',
            'ativo' => true,
        ];
    }
}
