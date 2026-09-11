<?php

namespace Tests\Feature\SortimentoPontoVenda;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/14-SORTIMENTO-PONTO-VENDA.md §3/§4/§5 — CRUD do sortimento pelo admin web
 * (`pontos_venda.gerenciar`), nos mesmos 4 níveis já usados em CampanhaItem.
 */
class SortimentoPontoVendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_adiciona_produto_ao_sortimento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Farinha de Trigo']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO',
            'produto_uuid' => $produto->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('item.tipo_item', 'PRODUTO')
            ->assertJsonPath('item.produto.descricao', 'Farinha de Trigo')
            ->assertJsonPath('item.usuario', null)
            ->assertJsonPath('item.status_aprovacao', null);
        $this->assertDatabaseCount('sortimentos_ponto_venda', 1);
    }

    public function test_admin_adiciona_secao_departamento_e_marca_inteira(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", ['tipo_item' => 'SECAO', 'secao_uuid' => $secao->uuid])->assertCreated();
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", ['tipo_item' => 'DEPARTAMENTO', 'departamento_uuid' => $departamento->uuid])->assertCreated();
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", ['tipo_item' => 'MARCA', 'marca_uuid' => $marca->uuid])->assertCreated();

        $this->assertDatabaseCount('sortimentos_ponto_venda', 3);
    }

    public function test_rejeita_produto_inativo_ou_pendente_de_aprovacao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produtoPendente = ProdutoAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'ativo' => false, 'status_aprovacao' => 'PENDENTE',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produtoPendente->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_uuid');

        $this->assertDatabaseCount('sortimentos_ponto_venda', 0);
    }

    public function test_rejeita_uuid_de_outro_nivel_quando_tipo_item_nao_bate(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'SECAO',
            'produto_uuid' => $produto->uuid,
        ])->assertStatus(422);
    }

    public function test_admin_remove_item_do_sortimento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $item = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->json('item');

        $this->deleteJson("/api/pontos-venda/{$pdv->uuid}/sortimento/{$item['id']}")->assertNoContent();

        $this->assertDatabaseCount('sortimentos_ponto_venda', 0);
    }

    public function test_nao_remove_item_de_outro_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvB = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $item = $this->postJson("/api/pontos-venda/{$pdvA->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->json('item');

        $this->deleteJson("/api/pontos-venda/{$pdvB->uuid}/sortimento/{$item['id']}")->assertNotFound();
        $this->assertDatabaseCount('sortimentos_ponto_venda', 1);
    }

    public function test_promotor_sem_permissao_nao_acessa_crud_do_admin(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->assertForbidden();
    }

    public function test_detalhe_do_pdv_inclui_sortimento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Nescau']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", ['tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid])->assertCreated();

        $response = $this->getJson("/api/pontos-venda/{$pdv->uuid}")->assertOk();

        $this->assertCount(1, $response->json('ponto_venda.sortimento'));
        $this->assertSame('Nescau', $response->json('ponto_venda.sortimento.0.produto.descricao'));
    }
}
