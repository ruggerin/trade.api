<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Perfil;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Perfil>
 */
class PerfilFactory extends Factory
{
    protected $model = Perfil::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nome' => $this->faker->jobTitle(),
            'descricao' => null,
            'permissoes' => [],
            'ativo' => true,
        ];
    }

    public function comPermissoes(array $permissoes): static
    {
        return $this->state(['permissoes' => $permissoes]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
