<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Perfil;
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

    /**
     * Perfil na mesma empresa com estas permissões (ex.: `tela.relatorios`, docs/64). Sem perfil,
     * GESTOR não vê nenhuma tela.
     *
     * @param  list<string>  $permissoes
     */
    public function comPerfil(array $permissoes): static
    {
        // Depois de criar: o empresa_id passado no create() só existe a essa altura.
        return $this->afterCreating(function (Usuario $usuario) use ($permissoes): void {
            $perfil = Perfil::factory()->comPermissoes($permissoes)->create(['empresa_id' => $usuario->empresa_id]);
            $usuario->forceFill(['perfil_id' => $perfil->id])->save();
        });
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
