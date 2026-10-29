<?php

namespace Tests\Feature;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\SecaoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/100: "Cada seção / linha" como lista guiada (nível + origem Mix da loja ou lista fixa) e o
 * checklist de produtos no formato "conferir item a item".
 */
class Doc100LinhasGuiadasEConferenciaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private SecaoAuditoria $limpeza;

    private SecaoAuditoria $bebidas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $depto = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Mercearia']);
        $this->limpeza = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Limpeza', 'departamento_id' => $depto->id]);
        $this->bebidas = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Bebidas', 'departamento_id' => $depto->id]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function criar(array $dados)
    {
        return $this->postJson('/api/tipos-registro', ['descricao' => 'Planograma', ...$dados]);
    }

    public function test_lista_fixa_de_secoes_volta_com_nomes_na_ordem_escolhida(): void
    {
        $outraEmpresa = SecaoAuditoria::create([
            'empresa_id' => Empresa::factory()->create()->id, 'descricao' => 'De fora',
            'departamento_id' => DepartamentoAuditoria::create(['empresa_id' => Empresa::factory()->create()->id, 'descricao' => 'X'])->id,
        ]);

        $tipo = $this->criar([
            'granularidade_padrao' => 'LINHA', 'permite_vincular_catalogo' => true,
            'linha_nivel' => 'SECAO', 'linha_origem' => 'FIXA',
            'linhas_fixas' => [$this->bebidas->uuid, $this->limpeza->uuid, $outraEmpresa->uuid, $this->bebidas->uuid],
        ])->assertCreated()->json('tipo_registro');

        $this->assertSame('SECAO', $tipo['linha_nivel']);
        $this->assertSame('FIXA', $tipo['linha_origem']);
        // Ordem do cadastro, sem repetição e sem item de outra empresa.
        $this->assertSame([
            ['id' => $this->bebidas->uuid, 'descricao' => 'Bebidas'],
            ['id' => $this->limpeza->uuid, 'descricao' => 'Limpeza'],
        ], $tipo['linhas_fixas']);
    }

    public function test_mix_da_loja_nao_guarda_lista_fixa(): void
    {
        $tipo = $this->criar([
            'granularidade_padrao' => 'LINHA', 'linha_nivel' => 'DEPARTAMENTO', 'linha_origem' => 'MIX_LOJA',
            'linhas_fixas' => [$this->limpeza->uuid],
        ])->assertCreated()->json('tipo_registro');

        $this->assertSame('DEPARTAMENTO', $tipo['linha_nivel']);
        $this->assertSame('MIX_LOJA', $tipo['linha_origem']);
        $this->assertNull($tipo['linhas_fixas']);
    }

    public function test_lista_guiada_so_vale_em_cada_secao_linha_sem_escolha_do_promotor(): void
    {
        // "O promotor escolhe" também grava LINHA, mas é livre.
        $this->assertNull($this->criar([
            'granularidade_padrao' => 'LINHA', 'escolhe_secao' => true, 'linha_nivel' => 'SECAO',
        ])->assertCreated()->json('tipo_registro.linha_nivel'));
        $this->assertNull($this->criar(['granularidade_padrao' => 'PRODUTO', 'linha_nivel' => 'SECAO'])->assertCreated()->json('tipo_registro.linha_nivel'));

        // Trocar pra "Cada produto" depois limpa a lista guiada.
        $uuid = $this->criar([
            'granularidade_padrao' => 'LINHA', 'linha_nivel' => 'SECAO', 'linha_origem' => 'FIXA', 'linhas_fixas' => [$this->limpeza->uuid],
        ])->json('tipo_registro.id');
        $this->putJson("/api/tipos-registro/{$uuid}", ['granularidade_padrao' => 'PRODUTO'])
            ->assertOk()
            ->assertJsonPath('tipo_registro.linha_nivel', null)
            ->assertJsonPath('tipo_registro.linhas_fixas', null);
    }

    public function test_registro_da_linha_vai_com_a_secao(): void
    {
        $tipo = $this->criar([
            'granularidade_padrao' => 'LINHA', 'permite_vincular_catalogo' => true, 'linha_nivel' => 'SECAO', 'linha_origem' => 'MIX_LOJA',
        ])->json('tipo_registro.id');
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $visita = $this->postJson('/api/visitas', ['ponto_venda_uuid' => $loja->uuid, 'latitude' => $loja->latitude, 'longitude' => $loja->longitude])->json('visita.id');

        $this->postJson("/api/visitas/{$visita}/registros", [
            'tipo_registro_uuid' => $tipo, 'tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->limpeza->uuid,
        ])->assertCreated()->assertJsonPath('registro.secao.id', $this->limpeza->uuid);
    }

    public function test_checklist_guarda_o_formato_conferir_item_a_item(): void
    {
        $campos = collect($this->criar([
            'campos' => [
                ['chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'sortimento_origem' => 'DINAMICO', 'sortimento_modo' => 'CONFERIR'],
                ['chave' => 'padrao', 'rotulo' => 'Padrão', 'tipo_campo' => 'SORTIMENTO', 'sortimento_origem' => 'DINAMICO'],
                ['chave' => 'obs', 'rotulo' => 'Obs', 'tipo_campo' => 'TEXTO', 'sortimento_modo' => 'CONFERIR'],
            ],
        ])->assertCreated()->json('tipo_registro.campos'))->keyBy('chave');

        $this->assertSame('CONFERIR', $campos['mix']['sortimento_modo']);
        $this->assertSame('PRESENTE', $campos['padrao']['sortimento_modo']);
        // Fora do checklist o formato não vale.
        $this->assertSame('PRESENTE', $campos['obs']['sortimento_modo']);

        $this->criar(['campos' => [['chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'sortimento_origem' => 'DINAMICO', 'sortimento_modo' => 'OUTRO']]])
            ->assertStatus(422);
    }
}
