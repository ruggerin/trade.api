<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** docs/65-TEMA-ESCURO.md — cada usuário escolhe o tema do próprio admin. */
class Doc65TemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_escolhe_o_proprio_tema_e_o_me_devolve(): void
    {
        $gestor = Usuario::factory()->gestor()->create();
        Sanctum::actingAs($gestor);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.tema', null);
        $this->putJson('/api/auth/me/preferencias', ['tema' => 'escuro'])->assertOk()->assertJsonPath('usuario.tema', 'escuro');
        $this->getJson('/api/auth/me')->assertJsonPath('usuario.tema', 'escuro');
    }

    public function test_tema_invalido_e_recusado(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create());
        $this->putJson('/api/auth/me/preferencias', ['tema' => 'roxo'])->assertUnprocessable();
    }
}
