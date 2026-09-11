<?php

namespace Database\Factories;

use App\Enums\PlanoEmpresa;
use App\Models\Empresa;
use App\Models\TipoRegistro;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    /**
     * Toda empresa nasce com os 3 tipos de registro "de fábrica" (mesmo comportamento de
     * EmpresaController::signup/storeSuperadmin, ver TipoRegistro::seedPadrao) — sem isso, os
     * testes que fazem `Empresa::factory()->create()` (a maioria) cairiam numa empresa sem
     * nenhum tipo cadastrado, e nenhum registro de visita conseguiria ser criado.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Empresa $empresa): void {
            TipoRegistro::seedPadrao($empresa->id);
        });
    }

    public function definition(): array
    {
        return [
            'razao_social' => $this->faker->company().' LTDA',
            'nome_fantasia' => $this->faker->company(),
            'cnpj' => $this->faker->unique()->numerify('##############'),
            'plano' => PlanoEmpresa::GRATUITO,
            'limite_usuarios' => 3,
            'limite_pontos_venda' => 3,
            'ativo' => true,
        ];
    }

    public function bloqueada(): static
    {
        return $this->state(['ativo' => false]);
    }

    public function semLimite(): static
    {
        return $this->state(['limite_usuarios' => null, 'limite_pontos_venda' => null]);
    }
}
