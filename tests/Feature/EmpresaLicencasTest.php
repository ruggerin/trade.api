<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Licença = usuário PROMOTOR ativo (cada um trava 1 dispositivo por vez, ver
 * AuthController::login) — limite separado de limite_usuarios (headcount geral do plano).
 * Ver docs/02-API-BACKEND.md, regra de negócio de licenças.
 */
class EmpresaLicencasTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloqueia_criar_promotor_alem_do_limite_de_licencas(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/usuarios', [
            'nome' => 'Segundo Promotor',
            'email' => 'segundo@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('usuarios', ['email' => 'segundo@empresa.com']);
    }

    public function test_promotor_inativo_nao_conta_pra_limite_de_licencas(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->inativo()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Promotor Novo',
            'email' => 'novo@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ])->assertCreated();
    }

    public function test_limite_de_licencas_nao_afeta_criacao_de_admin_ou_gestor(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1, 'limite_usuarios' => null]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Novo Gestor',
            'email' => 'gestor@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'GESTOR',
        ])->assertCreated();
    }

    public function test_sem_limite_de_licencas_configurado_nao_bloqueia(): void
    {
        // limite_usuarios também null: senão o teste bate no limite geral de headcount (default
        // 3 do factory) antes mesmo de chegar na regra de licença que este teste quer isolar.
        $empresa = Empresa::factory()->create(['limite_licencas' => null, 'limite_usuarios' => null]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->count(5)->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Mais Um Promotor',
            'email' => 'maisum@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ])->assertCreated();
    }

    public function test_showsuperadmin_retorna_numeros_de_uso_corretos(): void
    {
        $empresa = Empresa::factory()->create([
            'limite_licencas' => 5,
            'limite_usuarios' => 10,
            'limite_pontos_venda' => 3,
        ]);
        Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->inativo()->create(['empresa_id' => $empresa->id]);

        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'ativo' => false]);

        $promotorComVisita = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Visita::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv1->id,
            'usuario_id' => $promotorComVisita->id,
            'status' => 'ABERTA',
            'inicio_data' => now(),
            'inicio_latitude' => $pdv1->latitude,
            'inicio_longitude' => $pdv1->longitude,
            'inicio_distancia_metros' => 0,
        ]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->getJson("/api/superadmin/empresas/{$empresa->uuid}")->assertOk();

        // 2 PROMOTOR ativos (o inativo não conta) — ver test_promotor_inativo_nao_conta acima.
        $response->assertJsonPath('uso.licencas_usadas', 2);
        $response->assertJsonPath('uso.licencas_limite', 5);
        // admin + promotor ativo + promotor inativo + promotor com visita = 4.
        $response->assertJsonPath('uso.usuarios_total', 4);
        $response->assertJsonPath('uso.usuarios_limite', 10);
        // 2 PDVs ativos (o inativo não conta).
        $response->assertJsonPath('uso.pontos_venda_total', 2);
        $response->assertJsonPath('uso.pontos_venda_limite', 3);
        $response->assertJsonPath('uso.visitas_total', 1);
        $response->assertJsonPath('uso.visitas_ultimos_30_dias', 1);
        $response->assertJsonPath('uso.pontos_venda_visitados', 1);
        $this->assertNotNull($response->json('uso.ultima_atividade_em'));
    }

    public function test_admin_comum_nao_acessa_showsuperadmin(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/superadmin/empresas/{$empresa->uuid}")->assertForbidden();
    }
}
