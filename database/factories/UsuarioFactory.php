<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nome' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'senha_hash' => Hash::make('senha-teste'),
            'user_type' => UserType::PROMOTOR,
            'ativo' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(['user_type' => UserType::ADMIN]);
    }

    public function gestor(): static
    {
        return $this->state(['user_type' => UserType::GESTOR]);
    }

    public function promotor(): static
    {
        return $this->state(['user_type' => UserType::PROMOTOR]);
    }

    // SUPERADMIN não pertence a nenhuma empresa (ver docs/02-API-BACKEND.md) — sobrescreve o
    // empresa_id do factory pai em vez de herdar Empresa::factory().
    public function superadmin(): static
    {
        return $this->state(['user_type' => UserType::SUPERADMIN, 'empresa_id' => null]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
