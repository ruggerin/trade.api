<?php

namespace Tests\Feature\SortimentoPontoVenda;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/14-SORTIMENTO-PONTO-VENDA.md §9 — autonomia do promotor sobre vincular produto ao
 * sortimento (`SORTIMENTO_AUTONOMIA_PROMOTOR`, default AUTONOMO) e cadastrar produto novo no
 * catálogo (`CATALOGO_AUTONOMIA_PROMOTOR`, default REQUER_APROVACAO) — 3 níveis:
 * DESABILITADO/AUTONOMO/REQUER_APROVACAO.
 */
class AutonomiaSortimentoTest extends TestCase
{
    use RefreshDatabase;

    private function definirAutonomia(Empresa $empresa, string $chave, string $valor): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => $chave, 'valor' => $valor]);
    }

    // ---- Vincular produto existente (sortimento) ----

    public function test_promotor_vincula_produto_existente_default_e_autonomo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/proprio", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('item.status_aprovacao', null)
            ->assertJsonPath('item.usuario.id', $promotor->uuid);
    }

    public function test_promotor_vincula_produto_modo_requer_aprovacao_nasce_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'SORTIMENTO_AUTONOMIA_PROMOTOR', 'REQUER_APROVACAO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/proprio", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('item.status_aprovacao', 'PENDENTE');
        // O item já existe, é usável — não fica bloqueado esperando aprovação.
        $this->assertDatabaseCount('sortimentos_ponto_venda', 1);
    }

    public function test_promotor_nao_vincula_produto_modo_desabilitado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'SORTIMENTO_AUTONOMIA_PROMOTOR', 'DESABILITADO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/proprio", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->assertForbidden();
        $this->assertDatabaseCount('sortimentos_ponto_venda', 0);
    }

    public function test_gestor_aprova_item_pendente_de_sortimento(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'SORTIMENTO_AUTONOMIA_PROMOTOR', 'REQUER_APROVACAO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $item = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/proprio", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->json('item');

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/{$item['id']}/aprovar");

        $response->assertOk()->assertJsonPath('item.status_aprovacao', null);
    }

    public function test_gestor_rejeita_item_pendente_de_sortimento_apaga_a_linha(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'SORTIMENTO_AUTONOMIA_PROMOTOR', 'REQUER_APROVACAO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $item = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/proprio", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->json('item');

        Sanctum::actingAs($admin);
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/{$item['id']}/rejeitar")->assertNoContent();

        $this->assertDatabaseCount('sortimentos_ponto_venda', 0);
    }

    public function test_aprovar_item_ja_valido_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $item = $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento", [
            'tipo_item' => 'PRODUTO', 'produto_uuid' => $produto->uuid,
        ])->json('item');

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/sortimento/{$item['id']}/aprovar")->assertStatus(422);
    }

    // ---- Cadastrar produto novo (catálogo) ----

    public function test_promotor_nao_cadastra_produto_default_e_requer_aprovacao(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Achocolatado em Pó', 'propriedade' => 'CONCORRENTE',
        ]);

        $response->assertCreated()
            ->assertJsonPath('produto.ativo', false)
            ->assertJsonPath('produto.status_aprovacao', 'PENDENTE')
            ->assertJsonPath('produto.criado_por.id', $promotor->uuid);
    }

    public function test_promotor_cadastra_produto_modo_autonomo_nasce_ativo(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'CATALOGO_AUTONOMIA_PROMOTOR', 'AUTONOMO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Adoçante', 'propriedade' => 'PROPRIA',
        ]);

        $response->assertCreated()
            ->assertJsonPath('produto.ativo', true)
            ->assertJsonPath('produto.status_aprovacao', null);
    }

    public function test_promotor_nao_cadastra_produto_modo_desabilitado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->definirAutonomia($empresa, 'CATALOGO_AUTONOMIA_PROMOTOR', 'DESABILITADO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Não deveria existir', 'propriedade' => 'PROPRIA',
        ])->assertForbidden();
        $this->assertDatabaseCount('produtos_auditoria', 0);
    }

    public function test_gestor_aprova_produto_pendente_fica_ativo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $produto = $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Café Torrado', 'propriedade' => 'CONCORRENTE',
        ])->json('produto');

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/produtos-auditoria/{$produto['id']}/aprovar");

        $response->assertOk()
            ->assertJsonPath('produto.ativo', true)
            ->assertJsonPath('produto.status_aprovacao', null);
    }

    public function test_gestor_rejeita_produto_pendente_mantem_linha_e_inativo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $produto = $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Biscoito Genérico', 'propriedade' => 'CONCORRENTE',
        ])->json('produto');

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/produtos-auditoria/{$produto['id']}/rejeitar");

        $response->assertOk()
            ->assertJsonPath('produto.ativo', false)
            ->assertJsonPath('produto.status_aprovacao', 'REJEITADO');
        // Diferente do sortimento — a linha continua existindo (registro pode referenciá-la).
        $this->assertDatabaseCount('produtos_auditoria', 1);
    }

    public function test_promotor_nao_acessa_aprovar_ou_rejeitar_produto(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'ativo' => false, 'status_aprovacao' => 'PENDENTE',
        ]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/produtos-auditoria/{$produto->uuid}/aprovar")->assertForbidden();
        $this->postJson("/api/produtos-auditoria/{$produto->uuid}/rejeitar")->assertForbidden();
    }
}
