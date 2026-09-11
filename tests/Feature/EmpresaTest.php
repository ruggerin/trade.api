<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_cria_empresa_e_admin_e_retorna_token(): void
    {
        $response = $this->postJson('/api/empresas/signup', [
            'razao_social' => 'Nova Empresa LTDA',
            'nome_fantasia' => 'Nova Empresa',
            'cnpj' => '12345678000199',
            'admin_nome' => 'Dono',
            'admin_email' => 'dono@novaempresa.com',
            'admin_senha' => 'senha12345',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'usuario', 'empresa']);

        $this->assertDatabaseHas('empresas', ['nome_fantasia' => 'Nova Empresa', 'plano' => 'GRATUITO']);
        $this->assertDatabaseHas('usuarios', ['email' => 'dono@novaempresa.com', 'user_type' => 'ADMIN']);

        // Login automático — o token retornado já funciona.
        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_signup_rejeita_cnpj_duplicado(): void
    {
        Empresa::factory()->create(['cnpj' => '12345678000199']);

        $this->postJson('/api/empresas/signup', [
            'razao_social' => 'Outra LTDA',
            'nome_fantasia' => 'Outra',
            'cnpj' => '12345678000199',
            'admin_nome' => 'Fulano',
            'admin_email' => 'fulano@outra.com',
            'admin_senha' => 'senha12345',
        ])->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_show_retorna_dados_da_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create(['nome_fantasia' => 'Minha Empresa']);
        $usuario = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/empresa')->assertOk()->assertJsonPath('empresa.nome_fantasia', 'Minha Empresa');
    }

    public function test_admin_edita_razao_social_e_nome_fantasia_da_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create(['razao_social' => 'Antiga LTDA', 'nome_fantasia' => 'Antiga']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/empresa', [
            'razao_social' => 'Nova Razão Social LTDA',
            'nome_fantasia' => 'Novo Nome',
        ]);

        $response->assertOk()->assertJsonPath('empresa.nome_fantasia', 'Novo Nome');
        $this->assertDatabaseHas('empresas', [
            'id' => $empresa->id, 'razao_social' => 'Nova Razão Social LTDA', 'nome_fantasia' => 'Novo Nome',
        ]);
    }

    public function test_gestor_nao_edita_a_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->putJson('/api/empresa', ['nome_fantasia' => 'Não deveria salvar'])->assertForbidden();
    }

    public function test_editar_empresa_nao_aceita_trocar_plano_ou_limites(): void
    {
        $empresa = Empresa::factory()->create(['plano' => 'GRATUITO']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/empresa', ['nome_fantasia' => 'Novo Nome', 'plano' => 'BUSINESS'])->assertOk();

        // O campo plano nem existe nas regras de validação — é ignorado, não rejeitado.
        $this->assertDatabaseHas('empresas', ['id' => $empresa->id, 'plano' => 'GRATUITO', 'nome_fantasia' => 'Novo Nome']);
    }

    public function test_apenas_superadmin_acessa_lista_de_todas_as_empresas(): void
    {
        Empresa::factory()->count(3)->create();
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/superadmin/empresas')->assertOk();
        $this->assertCount(3, $response->json('empresas'));
    }

    public function test_admin_comum_nao_acessa_lista_de_empresas(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/superadmin/empresas')->assertForbidden();
    }

    public function test_superadmin_provisiona_empresa_com_primeiro_admin(): void
    {
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->postJson('/api/superadmin/empresas', [
            'razao_social' => 'Cliente Novo LTDA',
            'nome_fantasia' => 'Cliente Novo',
            'cnpj' => '98765432000188',
            'plano' => 'PRO',
            'admin_nome' => 'Admin Cliente',
            'admin_email' => 'admin@clientenovo.com',
            'admin_senha' => 'senha12345',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('empresas', ['nome_fantasia' => 'Cliente Novo', 'plano' => 'PRO']);
        $this->assertDatabaseHas('usuarios', ['email' => 'admin@clientenovo.com', 'user_type' => 'ADMIN']);
    }

    public function test_superadmin_edita_plano_e_limites_da_empresa(): void
    {
        $empresa = Empresa::factory()->create(['plano' => 'GRATUITO', 'limite_usuarios' => 3]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->putJson("/api/superadmin/empresas/{$empresa->uuid}", [
            'plano' => 'BUSINESS',
            'limite_usuarios' => 50,
        ])->assertOk()->assertJsonPath('empresa.plano', 'BUSINESS');

        $this->assertDatabaseHas('empresas', ['id' => $empresa->id, 'plano' => 'BUSINESS', 'limite_usuarios' => 50]);
    }

    public function test_bloquear_empresa_revoga_tokens_de_todos_os_usuarios_e_impede_login(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $token = $admin->createToken('teste')->plainTextToken;

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->deleteJson("/api/superadmin/empresas/{$empresa->uuid}")->assertNoContent();

        $this->assertDatabaseHas('empresas', ['id' => $empresa->id, 'ativo' => false]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // O guard já não tem mais o superadmin ativo (troquei de auth acima) — reseta antes de
        // testar o token do admin, senão reaproveita o cache do actingAs.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_reativar_empresa_permite_login_de_novo(): void
    {
        $empresa = Empresa::factory()->bloqueada()->create();
        $admin = Usuario::factory()->admin()->create([
            'empresa_id' => $empresa->id,
            'senha_hash' => bcrypt('senha-correta'),
        ]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->putJson("/api/superadmin/empresas/{$empresa->uuid}", ['ativo' => true])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'senha' => 'senha-correta',
        ])->assertOk();
    }
}
