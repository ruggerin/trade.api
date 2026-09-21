<?php

namespace Tests\Feature\Catalogo;

use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Busca rica de produtos (docs/27-BUSCA-MULTIPLA-DE-PRODUTOS.md): texto bate em descrição, código
 * de barras e código externo; filtro por marca; marca e código externo no cadastro.
 */
class BuscaProdutoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function produto(array $atributos): ProdutoAuditoria
    {
        return ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, ...$atributos]);
    }

    private function descricoes(string $query): array
    {
        return collect($this->getJson('/api/produtos-auditoria?'.$query)->assertOk()->json('produtos'))
            ->pluck('descricao')->all();
    }

    public function test_busca_por_codigo_de_barras(): void
    {
        $this->produto(['descricao' => 'Amaciante A', 'codigo_barras' => '7891000100103']);
        $this->produto(['descricao' => 'Sabão B', 'codigo_barras' => '7899999999999']);

        $this->assertSame(['Amaciante A'], $this->descricoes('busca=78910001'));
    }

    public function test_busca_por_codigo_externo(): void
    {
        $this->produto(['descricao' => 'Amaciante A', 'codigo_externo' => 'ERP-0042']);
        $this->produto(['descricao' => 'Sabão B', 'codigo_externo' => 'ERP-0099']);

        $this->assertSame(['Amaciante A'], $this->descricoes('busca=ERP-0042'));
    }

    public function test_busca_por_descricao_continua_funcionando(): void
    {
        $this->produto(['descricao' => 'Amaciante A']);
        $this->produto(['descricao' => 'Sabão B']);

        $this->assertSame(['Sabão B'], $this->descricoes('busca=sab'));
    }

    public function test_busca_combinada_com_marca_nao_vaza_o_or(): void
    {
        $marcaX = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $marcaY = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->produto(['descricao' => 'Amaciante X', 'marca_id' => $marcaX->id]);
        $this->produto(['descricao' => 'Amaciante Y', 'marca_id' => $marcaY->id]);

        $this->assertSame(['Amaciante X'], $this->descricoes('busca=amaciante&marca_uuid='.$marcaX->uuid));
    }

    public function test_filtro_por_marca_restringe_e_sem_filtro_traz_produto_sem_marca(): void
    {
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->produto(['descricao' => 'Com marca', 'marca_id' => $marca->id]);
        $this->produto(['descricao' => 'Sem marca']);

        $this->assertSame(['Com marca'], $this->descricoes('marca_uuid='.$marca->uuid));
        $this->assertEqualsCanonicalizing(['Com marca', 'Sem marca'], $this->descricoes(''));
    }

    public function test_cadastro_e_edicao_com_marca_e_codigo_externo(): void
    {
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);

        $id = $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Novo', 'propriedade' => 'PROPRIA',
            'marca_uuid' => $marca->uuid, 'codigo_externo' => 'ERP-1',
        ])->assertCreated()
            ->assertJsonPath('produto.marca.id', $marca->uuid)
            ->assertJsonPath('produto.codigo_externo', 'ERP-1')
            ->json('produto.id');

        $this->putJson('/api/produtos-auditoria/'.$id, ['marca_uuid' => null])
            ->assertOk()->assertJsonPath('produto.marca', null);
    }

    public function test_marca_de_outra_empresa_e_rejeitada(): void
    {
        $outra = MarcaAuditoria::factory()->create(['empresa_id' => Empresa::factory()->create()->id]);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Novo', 'propriedade' => 'PROPRIA', 'marca_uuid' => $outra->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('marca_uuid');
    }
}
