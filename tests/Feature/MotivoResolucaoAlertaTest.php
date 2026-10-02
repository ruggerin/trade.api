<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\MotivoResolucaoAlerta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catálogo de motivos de fechamento rápido de alerta (docs/56) — CRUD simples por empresa,
 * mesmo padrão de RamoAtividade.
 */
class MotivoResolucaoAlertaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_lista_e_desativa_motivo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $criado = $this->postJson('/api/motivos-resolucao-alerta', ['descricao' => 'Ruptura da indústria'])
            ->assertCreated()
            ->json('motivo_resolucao_alerta');

        $this->getJson('/api/motivos-resolucao-alerta')
            ->assertOk()
            ->assertJsonFragment(['descricao' => 'Ruptura da indústria']);

        $this->deleteJson("/api/motivos-resolucao-alerta/{$criado['id']}")->assertNoContent();

        $this->assertDatabaseHas('motivos_resolucao_alerta', ['uuid' => $criado['id'], 'ativo' => false]);
    }

    public function test_isolamento_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        MotivoResolucaoAlerta::create(['empresa_id' => $empresaA->id, 'descricao' => 'Motivo da empresa A']);
        MotivoResolucaoAlerta::create(['empresa_id' => $empresaB->id, 'descricao' => 'Motivo da empresa B']);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($adminA);

        $motivos = $this->getJson('/api/motivos-resolucao-alerta')->assertOk()->json('motivos_resolucao_alerta');

        $this->assertCount(1, $motivos);
        $this->assertSame('Motivo da empresa A', $motivos[0]['descricao']);
    }

    public function test_promotor_nao_gerencia_o_catalogo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/motivos-resolucao-alerta', ['descricao' => 'Qualquer coisa'])->assertForbidden();
    }

    public function test_gestor_sem_permissao_de_catalogo_nao_gerencia(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/motivos-resolucao-alerta', ['descricao' => 'Qualquer coisa'])->assertForbidden();
    }
}
