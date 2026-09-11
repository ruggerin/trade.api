<?php

namespace Tests\Feature\Auth;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#autenticação — regras específicas desta API (nada de Laravel padrão):
 * senha em coluna própria (senha_hash), trava de 1 dispositivo por PROMOTOR, bloqueio por
 * empresa desativada, SUPERADMIN nunca afetado por essa segunda checagem.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_com_credenciais_corretas_retorna_token_e_usuario(): void
    {
        $usuario = Usuario::factory()->admin()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'usuario' => ['id', 'nome', 'email']])
            ->assertJsonPath('usuario.email', $usuario->email);
    }

    public function test_login_com_senha_errada_retorna_422(): void
    {
        $usuario = Usuario::factory()->admin()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-errada',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_com_email_inexistente_retorna_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'ninguem@empresa.com',
            'senha' => 'qualquer',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_de_usuario_inativo_retorna_422(): void
    {
        $usuario = Usuario::factory()->admin()->inativo()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_de_usuario_com_empresa_bloqueada_retorna_422(): void
    {
        $empresa = Empresa::factory()->bloqueada()->create();
        $usuario = Usuario::factory()->admin()->create([
            'empresa_id' => $empresa->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ]);

        $response->assertStatus(422);
    }

    public function test_superadmin_nunca_e_bloqueado_por_empresa_pois_nao_pertence_a_nenhuma(): void
    {
        $usuario = Usuario::factory()->superadmin()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ]);

        $response->assertOk();
    }

    public function test_login_de_promotor_sem_dispositivo_identificador_retorna_422(): void
    {
        $usuario = Usuario::factory()->promotor()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('dispositivo_identificador');
    }

    public function test_login_de_promotor_com_dispositivo_identificador_grava_o_dispositivo(): void
    {
        $usuario = Usuario::factory()->promotor()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => 'aparelho-1',
            'dispositivo_nome' => 'Moto G',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('dispositivos', [
            'usuario_id' => $usuario->id,
            'identificador' => 'aparelho-1',
            'nome' => 'Moto G',
        ]);
    }

    public function test_promotor_logando_num_aparelho_novo_derruba_a_sessao_do_aparelho_antigo(): void
    {
        $usuario = Usuario::factory()->promotor()->create([
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $primeiroLogin = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => 'aparelho-antigo',
        ])->json();

        $tokenAntigo = $primeiroLogin['token'];

        // Token antigo funciona antes do segundo login.
        $this->withToken($tokenAntigo)->getJson('/api/auth/me')->assertOk();

        // O guard do sanctum memoiza o usuário resolvido na primeira chamada autenticada desta
        // mesma execução de teste (o container/app não é recriado entre requests simulados) —
        // sem isso, a próxima chamada abaixo reaproveitaria esse cache em vez de revalidar o
        // token contra o banco, mascarando a revogação. Nunca acontece em produção (cada
        // request HTTP real é um processo PHP novo).
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => 'aparelho-novo',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Token antigo foi revogado pelo login no aparelho novo.
        $this->withToken($tokenAntigo)->getJson('/api/auth/me')->assertStatus(401);

        $this->assertDatabaseHas('dispositivos', [
            'usuario_id' => $usuario->id,
            'identificador' => 'aparelho-novo',
        ]);
        $this->assertDatabaseCount('dispositivos', 1);
    }

    public function test_logout_invalida_o_token_atual(): void
    {
        $usuario = Usuario::factory()->admin()->create();
        $token = $usuario->createToken('teste')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        // Ver comentário equivalente no teste de troca de aparelho acima — sem isso o guard
        // reaproveita o usuário já resolvido na chamada anterior desta mesma execução de teste.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_retorna_dados_da_empresa_para_usuario_normal(): void
    {
        $usuario = Usuario::factory()->admin()->create();
        $token = $usuario->createToken('teste')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('usuario.empresa.id', $usuario->empresa->uuid);
    }
}
