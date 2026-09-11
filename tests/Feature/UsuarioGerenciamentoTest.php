<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#usuários — GET /usuarios/{uuid} (detalhe) e
 * DELETE /usuarios/{uuid}/dispositivo (revogar sessão do aparelho, sem esperar o promotor
 * logar de novo pra liberar a licença).
 */
class UsuarioGerenciamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_usuario_com_perfil_e_dispositivo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'nome' => 'Moto G',
            'ultimo_acesso_em' => now(),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/usuarios/{$promotor->uuid}");

        $response->assertOk()
            ->assertJsonPath('usuario.id', $promotor->uuid)
            ->assertJsonPath('usuario.dispositivo.identificador', 'aparelho-123');
    }

    public function test_admin_atribui_perfil_a_promotor(): void
    {
        // docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md — Perfil deixou de ser exclusivo de GESTOR.
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $perfil = Perfil::factory()->comPermissoes(['pontos_venda.visualizar_todos'])->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/usuarios/{$promotor->uuid}", ['perfil_uuid' => $perfil->uuid]);

        $response->assertOk()->assertJsonPath('usuario.perfil.id', $perfil->uuid);
    }

    public function test_perfil_rejeitado_para_admin(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $perfil = Perfil::factory()->create(['empresa_id' => $empresa->id]);
        $outroAdmin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$outroAdmin->uuid}", ['perfil_uuid' => $perfil->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('perfil_uuid');
    }

    public function test_admin_revoga_dispositivo_do_promotor_e_derruba_a_sessao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'ultimo_acesso_em' => now(),
        ]);
        $promotor->createToken('acesso-api');
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo");

        $response->assertNoContent();
        $this->assertDatabaseMissing('dispositivos', ['usuario_id' => $promotor->id]);
        $this->assertSame(0, $promotor->tokens()->count());
    }

    public function test_revogar_dispositivo_sem_nenhum_vinculado_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo")->assertStatus(422);
    }

    public function test_promotor_nao_acessa_rotas_de_gerenciamento_de_usuarios(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/usuarios/{$outroPromotor->uuid}")->assertForbidden();
        $this->deleteJson("/api/usuarios/{$outroPromotor->uuid}/dispositivo")->assertForbidden();
    }

    public function test_superadmin_nao_revoga_dispositivo_delete_continua_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'ultimo_acesso_em' => now(),
        ]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo")->assertForbidden();
    }

    public function test_admin_vincula_centro_de_custo_a_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $centroCusto = \App\Models\CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/usuarios/{$promotor->uuid}", [
            'centro_custo_uuid' => $centroCusto->uuid,
        ]);

        $response->assertOk()->assertJsonPath('usuario.centro_custo.id', $centroCusto->uuid);
        $this->assertDatabaseHas('usuarios', ['id' => $promotor->id, 'centro_custo_id' => $centroCusto->id]);
    }

    public function test_centro_custo_rejeitado_para_usuario_que_nao_e_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $centroCusto = \App\Models\CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$gestor->uuid}", ['centro_custo_uuid' => $centroCusto->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('centro_custo_uuid');
    }

    public function test_centro_custo_de_outra_empresa_e_rejeitado(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $centroCustoDeOutraEmpresa = \App\Models\CentroCusto::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$promotor->uuid}", ['centro_custo_uuid' => $centroCustoDeOutraEmpresa->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('centro_custo_uuid');
    }
}
