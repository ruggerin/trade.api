<?php

namespace Tests\Feature\Campanha;

use App\Enums\TipoItemCampanha;
use App\Models\CampanhaAuditoria;
use App\Models\CampanhaItem;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\MarcaDepartamento;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md, regra de negócio 2 — o algoritmo mais complexo da API: resolve
 * "o que auditar" num PDV combinando 4 tipos de item de campanha (PRODUTO/SECAO/DEPARTAMENTO/
 * MARCA) livremente misturados, com dedup por produto. GET /campanhas-auditoria/disponiveis.
 */
class DisponiveisTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(Empresa $empresa): Usuario
    {
        $usuario = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function itemCampanha(CampanhaAuditoria $campanha, TipoItemCampanha $tipo, array $fks): CampanhaItem
    {
        return CampanhaItem::create([
            'campanha_id' => $campanha->id,
            'tipo_item' => $tipo,
            ...$fks,
        ]);
    }

    public function test_item_tipo_produto_resolve_apenas_aquele_produto(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]); // não deve aparecer

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::PRODUTO, ['produto_id' => $produto->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")
            ->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid');
        $this->assertEquals([$produto->uuid], $uuids->all());
    }

    public function test_item_tipo_secao_resolve_todos_os_produtos_daquela_secao(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);
        $outraSecao = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);

        $p1 = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        $p2 = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $outraSecao->id]); // não deve aparecer

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::SECAO, ['secao_id' => $secao->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid')->sort()->values();
        $this->assertEquals(collect([$p1->uuid, $p2->uuid])->sort()->values()->all(), $uuids->all());
    }

    public function test_item_tipo_departamento_resolve_produtos_de_todas_as_secoes(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $secaoX = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);

        $p1 = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);
        $p2 = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id, 'secao_id' => $secaoX->id]);

        $outroDepartamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $outroDepartamento->id]);

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::DEPARTAMENTO, ['departamento_id' => $departamento->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid')->sort()->values();
        $this->assertEquals(collect([$p1->uuid, $p2->uuid])->sort()->values()->all(), $uuids->all());
    }

    public function test_item_tipo_marca_resolve_produtos_dos_departamentos_ligados_a_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        MarcaDepartamento::create(['marca_id' => $marca->id, 'departamento_id' => $departamento->id]);

        $p1 = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);

        $outroDepartamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $outroDepartamento->id]); // não deve aparecer

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::MARCA, ['marca_id' => $marca->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid');
        $this->assertEquals([$p1->uuid], $uuids->all());
    }

    public function test_dedup_quando_dois_itens_diferentes_resolvem_o_mesmo_produto(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        MarcaDepartamento::create(['marca_id' => $marca->id, 'departamento_id' => $departamento->id]);

        // Coberto tanto pelo item SECAO quanto pelo item MARCA (mesmo departamento) — precisa
        // aparecer uma única vez na resposta.
        $produto = ProdutoAuditoria::factory()->create([
            'empresa_id' => $empresa->id,
            'departamento_id' => $departamento->id,
            'secao_id' => $secao->id,
        ]);

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::SECAO, ['secao_id' => $secao->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::MARCA, ['marca_id' => $marca->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid');
        $this->assertCount(1, $uuids);
        $this->assertEquals($produto->uuid, $uuids->first());
    }

    public function test_produto_inativo_nao_aparece_mesmo_coberto_pela_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $ativo = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);
        // Produto pendente de aprovação (self-service, ver docs/14-SORTIMENTO-PONTO-VENDA.md §8)
        // ou desativado por outro motivo qualquer — nunca deveria aparecer no checklist.
        $pendente = ProdutoAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'departamento_id' => $departamento->id,
            'ativo' => false, 'status_aprovacao' => 'PENDENTE',
        ]);

        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::DEPARTAMENTO, ['departamento_id' => $departamento->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $uuids = collect($response->json('produtos'))->pluck('produto_uuid');
        $this->assertEquals([$ativo->uuid], $uuids->all());
        $this->assertNotContains($pendente->uuid, $uuids);
    }

    public function test_campanha_inativa_e_ignorada(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->inativa()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanha, TipoItemCampanha::PRODUTO, ['produto_id' => $produto->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $this->assertCount(0, $response->json('produtos'));
    }

    public function test_campanha_fora_da_vigencia_e_ignorada(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $produtoExpirado = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $campanhaExpirada = CampanhaAuditoria::factory()->expirada()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanhaExpirada, TipoItemCampanha::PRODUTO, ['produto_id' => $produtoExpirado->id]);

        $produtoFuturo = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $campanhaFutura = CampanhaAuditoria::factory()->futura()->create(['empresa_id' => $empresa->id]);
        $this->itemCampanha($campanhaFutura, TipoItemCampanha::PRODUTO, ['produto_id' => $produtoFuturo->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $this->assertCount(0, $response->json('produtos'));
    }

    public function test_nao_mistura_campanhas_de_outra_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $this->autenticar($empresaA);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);

        $produtoDeB = ProdutoAuditoria::factory()->create(['empresa_id' => $empresaB->id]);
        $campanhaDeB = CampanhaAuditoria::factory()->create(['empresa_id' => $empresaB->id]);
        $this->itemCampanha($campanhaDeB, TipoItemCampanha::PRODUTO, ['produto_id' => $produtoDeB->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $this->assertCount(0, $response->json('produtos'));
    }

    public function test_ponto_de_venda_inexistente_retorna_404(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);

        $this->getJson('/api/campanhas-auditoria/disponiveis?ponto_venda_uuid=uuid-que-nao-existe')
            ->assertNotFound();
    }

    public function test_resposta_traz_dados_do_produto_e_da_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $this->autenticar($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Refrigerante 2L']);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Campanha Verão']);
        $this->itemCampanha($campanha, TipoItemCampanha::PRODUTO, ['produto_id' => $produto->id]);

        $response = $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")->assertOk();

        $response->assertJsonFragment([
            'produto_uuid' => $produto->uuid,
            'descricao' => 'Refrigerante 2L',
            'campanha_uuid' => $campanha->uuid,
            'campanha_descricao' => 'Campanha Verão',
        ]);
    }
}
