<?php

namespace Tests\Feature\Campanha;

use App\Models\CampanhaAuditoria;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampanhaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/campanhas-auditoria', [
            'descricao' => 'Campanha de Verão',
            'vigencia_inicio' => now()->toDateString(),
            'vigencia_fim' => now()->addMonth()->toDateString(),
        ]);

        $response->assertCreated()->assertJsonPath('campanha.descricao', 'Campanha de Verão');
    }

    public function test_promotor_nao_cria_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/campanhas-auditoria', [
            'descricao' => 'Campanha de Verão',
            'vigencia_inicio' => now()->toDateString(),
            'vigencia_fim' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_vigencia_fim_antes_do_inicio_e_invalida(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/campanhas-auditoria', [
            'descricao' => 'Campanha Inválida',
            'vigencia_inicio' => now()->toDateString(),
            'vigencia_fim' => now()->subMonth()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('vigencia_fim');
    }

    public function test_destroy_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/campanhas-auditoria/{$campanha->uuid}")->assertNoContent();

        $this->assertDatabaseHas('campanhas_auditoria', ['id' => $campanha->id, 'ativo' => false]);
    }

    public function test_qualquer_autenticado_le_campanhas_mesmo_sem_permissao(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/campanhas-auditoria')->assertOk();
    }

    public function test_adiciona_item_do_tipo_produto_a_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/campanhas-auditoria/{$campanha->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produto->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('item.produto.id', $produto->uuid);
    }

    public function test_item_rejeita_uuid_de_tipo_errado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        // tipo_item = PRODUTO mas manda departamento_uuid em vez de produto_uuid.
        $response = $this->postJson("/api/campanhas-auditoria/{$campanha->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'departamento_uuid' => $departamento->uuid,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['produto_uuid', 'departamento_uuid']);
    }

    public function test_item_rejeita_uuid_de_produto_de_outra_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresaA->id]);
        $produtoDeOutraEmpresa = ProdutoAuditoria::factory()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/campanhas-auditoria/{$campanha->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produtoDeOutraEmpresa->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_uuid');
    }

    public function test_item_rejeita_produto_inativo_ou_pendente_de_aprovacao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $produtoPendente = ProdutoAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'ativo' => false, 'status_aprovacao' => 'PENDENTE',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/campanhas-auditoria/{$campanha->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produtoPendente->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_uuid');

        $this->assertDatabaseCount('campanha_itens', 0);
    }

    public function test_remove_item_da_campanha_com_hard_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $itemUuid = $this->postJson("/api/campanhas-auditoria/{$campanha->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produto->uuid,
        ])->json('item.id');

        $this->deleteJson("/api/campanhas-auditoria/{$campanha->uuid}/itens/{$itemUuid}")->assertNoContent();

        $this->assertDatabaseCount('campanha_itens', 0);
    }

    public function test_nao_remove_item_de_campanha_diferente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanhaA = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $campanhaB = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $itemUuid = $this->postJson("/api/campanhas-auditoria/{$campanhaA->uuid}/itens", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produto->uuid,
        ])->json('item.id');

        $this->deleteJson("/api/campanhas-auditoria/{$campanhaB->uuid}/itens/{$itemUuid}")->assertNotFound();
        $this->assertDatabaseCount('campanha_itens', 1);
    }
}
