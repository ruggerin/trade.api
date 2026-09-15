<?php

namespace Tests\Feature;

use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/tipos-registro/campos/{campo}/sortimento — checklist resolvido de um campo
 * SORTIMENTO pra um PDV, ver App\Support\ResolverSortimentoCampo e decisão 3 de
 * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
 */
class CampoSortimentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dinamico_so_lista_produtos_do_recorte_ja_no_sortimento_do_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes', 'departamento_id' => $departamento->id]);
        $outraSecao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Sabão', 'departamento_id' => $departamento->id]);

        $noSortimento = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        $foraDoSortimento = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        $deOutraSecao = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $outraSecao->id]);

        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $noSortimento->id]);
        // Produto de outra seção também no sortimento do PDV, mas fora do recorte do campo.
        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $deOutraSecao->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        $campo = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $secao->id,
        ]);
        Sanctum::actingAs($promotor);

        $response = $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$pdv->uuid}");

        $response->assertOk()->assertJsonCount(1, 'produtos')
            ->assertJsonPath('produtos.0.produto_uuid', $noSortimento->uuid);

        // $foraDoSortimento nunca apareceu em nenhuma consulta — só confirma que não vaza.
        $this->assertNotEquals($foraDoSortimento->uuid, $response->json('produtos.0.produto_uuid'));
    }

    public function test_fixo_lista_a_lista_curada_independente_do_sortimento_do_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        $campo = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'FIXO',
        ]);
        $campo->produtosFixos()->attach($produto->id);
        Sanctum::actingAs($promotor);

        $response = $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$pdv->uuid}");

        $response->assertOk()->assertJsonCount(1, 'produtos')
            ->assertJsonPath('produtos.0.produto_uuid', $produto->uuid);
    }

    public function test_campo_de_outra_empresa_retorna_404(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresaB->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresaB->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresaA->id, 'descricao' => 'Loja Perfeita']);
        $campo = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'FIXO',
        ]);
        Sanctum::actingAs($promotorB);

        $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$pdv->uuid}")
            ->assertNotFound();
    }
}
