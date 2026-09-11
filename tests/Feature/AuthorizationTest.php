<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#autorização — as duas camadas de middleware (App\Http\Middleware\
 * EnsurePermissao e EnsureUserType), usando pontos_venda.gerenciar (permissao) e a rota de
 * perfis (user_type:ADMIN) como representantes do padrão aplicado em todos os outros grupos.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function payloadPontoVenda(): array
    {
        return [
            'razao_social' => 'Loja LTDA',
            'fantasia' => 'Loja',
            'latitude' => -3.10,
            'longitude' => -60.02,
            'endereco' => 'Rua Y, 1',
            'cidade' => 'Manaus',
        ];
    }

    public function test_admin_sempre_libera_rota_com_permissao_mesmo_sem_perfil(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertCreated();
    }

    public function test_promotor_nunca_libera_rota_de_permissao(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertForbidden();
    }

    public function test_gestor_sem_perfil_nao_libera_rota_de_permissao(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => null]);

        Sanctum::actingAs($gestor);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertForbidden();
    }

    public function test_gestor_com_perfil_sem_a_permissao_nao_libera(): void
    {
        $empresa = Empresa::factory()->create();
        $perfil = Perfil::factory()->comPermissoes([Permissao::CATALOGO_GERENCIAR->value])
            ->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);

        Sanctum::actingAs($gestor);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertForbidden();
    }

    public function test_gestor_com_perfil_ativo_com_a_permissao_libera(): void
    {
        $empresa = Empresa::factory()->create();
        $perfil = Perfil::factory()->comPermissoes([Permissao::PONTOS_VENDA_GERENCIAR->value])
            ->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);

        Sanctum::actingAs($gestor);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertCreated();
    }

    public function test_gestor_com_perfil_desativado_perde_a_permissao(): void
    {
        $empresa = Empresa::factory()->create();
        $perfil = Perfil::factory()->comPermissoes([Permissao::PONTOS_VENDA_GERENCIAR->value])
            ->inativo()
            ->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);

        Sanctum::actingAs($gestor);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertForbidden();
    }

    public function test_superadmin_nao_libera_rota_de_permissao_fora_de_usuarios_gerenciar(): void
    {
        $superadmin = Usuario::factory()->superadmin()->create();

        Sanctum::actingAs($superadmin);
        $this->postJson('/api/pontos-venda', $this->payloadPontoVenda())->assertForbidden();
    }

    public function test_rota_de_perfis_exige_user_type_admin_exato(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $superadmin = Usuario::factory()->superadmin()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($gestor);
        $this->getJson('/api/perfis')->assertForbidden();

        Sanctum::actingAs($superadmin);
        $this->getJson('/api/perfis')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/perfis')->assertOk();
    }

    public function test_rota_superadmin_bloqueia_admin_e_gestor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/superadmin/empresas')->assertForbidden();
    }
}
