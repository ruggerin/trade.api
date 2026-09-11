<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#usuários — SUPERADMIN é um caso à parte no EnsurePermissao: só passa
 * em usuarios.gerenciar, e só em GET/POST/PUT (nunca DELETE).
 */
class UsuarioSuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_cria_usuario_em_qualquer_empresa_via_empresa_uuid(): void
    {
        $empresa = Empresa::factory()->create();
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->postJson('/api/usuarios', [
            'nome' => 'Promotor Via Suporte',
            'email' => 'promotor@empresacliente.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
            'empresa_uuid' => $empresa->uuid,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('usuarios', ['email' => 'promotor@empresacliente.com', 'empresa_id' => $empresa->id]);
    }

    public function test_superadmin_sem_empresa_uuid_recebe_422(): void
    {
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Sem Empresa',
            'email' => 'sememp@teste.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ])->assertStatus(422)->assertJsonValidationErrors('empresa_uuid');
    }

    public function test_admin_nao_pode_escolher_empresa_ao_criar_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Tentativa',
            'email' => 'tentativa@teste.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
            'empresa_uuid' => $outraEmpresa->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('empresa_uuid');
    }

    public function test_superadmin_edita_usuario_de_qualquer_empresa_inclusive_senha(): void
    {
        $empresa = Empresa::factory()->create();
        $usuario = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->putJson("/api/usuarios/{$usuario->uuid}", [
            'nome' => 'Nome Corrigido Pelo Suporte',
            'senha' => 'novasenha123',
        ]);

        $response->assertOk()->assertJsonPath('usuario.nome', 'Nome Corrigido Pelo Suporte');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'novasenha123',
            'dispositivo_identificador' => 'qualquer',
        ])->assertOk();
    }

    public function test_superadmin_nao_pode_deletar_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $usuario = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->deleteJson("/api/usuarios/{$usuario->uuid}")->assertForbidden();
    }

    public function test_usuario_nao_pode_desativar_a_si_mesmo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/usuarios/{$admin->uuid}")->assertStatus(422);
        $this->assertDatabaseHas('usuarios', ['id' => $admin->id, 'ativo' => true]);
    }

    public function test_desativar_usuario_revoga_tokens_ja_emitidos(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tokenDoPromotor = $promotor->createToken('teste')->plainTextToken;

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/usuarios/{$promotor->uuid}")->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenDoPromotor)->getJson('/api/auth/me')->assertStatus(401);
    }
}
