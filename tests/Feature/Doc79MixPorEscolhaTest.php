<?php

namespace Tests\Feature;

use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\MarcaDepartamento;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/79-CHECKLIST-MIX-POR-ESCOLHA-DO-PROMOTOR.md — promotor escolhe marca/departamento/seção
 * no início do formulário e o Mix segue a escolha; mix cadastrado por recorte inteiro vira os
 * produtos daquele recorte; produto fora do mix pode ser adicionado dentro do recorte.
 */
class Doc79MixPorEscolhaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private DepartamentoAuditoria $limpeza;

    private SecaoAuditoria $amaciante;

    private SecaoAuditoria $lavaRoupas;

    private ProdutoAuditoria $amacianteAzul;

    private ProdutoAuditoria $amacianteRosa;

    private ProdutoAuditoria $sabaoPo;

    private ProdutoAuditoria $sabaoLiquido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->limpeza = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Limpeza']);
        $this->amaciante = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Amaciante', 'departamento_id' => $this->limpeza->id]);
        $this->lavaRoupas = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Lava-roupas', 'departamento_id' => $this->limpeza->id]);

        $produto = fn (string $descricao, SecaoAuditoria $secao) => ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'descricao' => $descricao,
            'secao_id' => $secao->id, 'departamento_id' => $this->limpeza->id,
        ]);
        $this->amacianteAzul = $produto('Amaciante Azul 2L', $this->amaciante);
        $this->amacianteRosa = $produto('Amaciante Rosa 2L', $this->amaciante);
        $this->sabaoPo = $produto('Sabão em pó 1kg', $this->lavaRoupas);
        $this->sabaoLiquido = $produto('Sabão líquido 3L', $this->lavaRoupas);
    }

    private function noMix(string $tipoItem, array $colunas): void
    {
        SortimentoPontoVenda::create(['ponto_venda_id' => $this->pdv->id, 'tipo_item' => $tipoItem, ...$colunas]);
    }

    private function formularioPontoExtra(array $flags = ['escolhe_departamento' => true, 'escolhe_secao' => true]): TipoRegistro
    {
        $tipo = TipoRegistro::create([
            'empresa_id' => $this->empresa->id, 'descricao' => 'Ponto Extra', 'granularidade_padrao' => 'LINHA', ...$flags,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_segue_escolha' => true,
        ]);

        return $tipo;
    }

    private function abrirVisita(): string
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'latitude' => $this->pdv->latitude,
            'longitude' => $this->pdv->longitude,
        ])->json('visita.id');
    }

    private function registrar(string $visitaUuid, TipoRegistro $tipo, array $vinculo, array $mix)
    {
        return $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) Str::uuid(),
            ...$vinculo,
            'valores_campos' => ['mix' => json_encode($mix)],
        ]);
    }

    public function test_mix_cadastrado_como_secao_inteira_lista_todos_os_produtos_da_secao_mesmo_com_recorte_fixo(): void
    {
        $this->noMix('SECAO', ['secao_id' => $this->amaciante->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja Perfeita']);
        $campo = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'DEPARTAMENTO', 'sortimento_departamento_id' => $this->limpeza->id,
        ]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $uuids = $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$this->pdv->uuid}")
            ->assertOk()
            ->json('produtos.*.produto_uuid');

        $this->assertEqualsCanonicalizing([$this->amacianteAzul->uuid, $this->amacianteRosa->uuid], $uuids);
    }

    public function test_mix_que_segue_a_escolha_devolve_o_mix_inteiro_com_secao_e_os_recortes_escolhiveis(): void
    {
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Brisa']);
        // Marca vem do próprio produto (docs/82 §5.3), não de MarcaDepartamento.
        $this->amacianteAzul->update(['marca_id' => $marca->id]);
        $this->noMix('PRODUTO', ['produto_id' => $this->amacianteAzul->id]);
        $this->noMix('PRODUTO', ['produto_id' => $this->sabaoPo->id]);
        $campo = $this->formularioPontoExtra()->campos()->first();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $response = $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$this->pdv->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'produtos')
            ->assertJsonPath('recortes.departamentos.0.uuid', $this->limpeza->uuid)
            ->assertJsonPath('recortes.marcas.0.uuid', $marca->uuid)
            ->assertJsonPath('recortes.marcas.0.departamentos_uuids.0', $this->limpeza->uuid)
            ->assertJsonPath('recortes.marcas.0.secoes_uuids', [$this->amaciante->uuid])
            ->assertJsonCount(1, 'recortes.marcas')
            ->assertJsonCount(2, 'recortes.secoes');

        $amaciante = collect($response->json('produtos'))->firstWhere('produto_uuid', $this->amacianteAzul->uuid);
        $this->assertSame($this->amaciante->uuid, $amaciante['secao_uuid']);
        $this->assertSame($this->limpeza->uuid, $amaciante['departamento_uuid']);
    }

    public function test_admin_salva_formulario_com_escolha_e_granularidade_vira_linha(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto Extra',
            'granularidade_padrao' => 'PRODUTO',
            'escolhe_departamento' => true,
            'escolhe_secao' => true,
            'campos' => [[
                'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO',
                'sortimento_origem' => 'DINAMICO', 'sortimento_segue_escolha' => true,
            ]],
        ])->assertCreated();

        $response->assertJsonPath('tipo_registro.granularidade_padrao', 'LINHA')
            ->assertJsonPath('tipo_registro.escolhe_marca', false)
            ->assertJsonPath('tipo_registro.escolhe_departamento', true)
            ->assertJsonPath('tipo_registro.campos.0.sortimento_segue_escolha', true)
            ->assertJsonPath('tipo_registro.campos.0.sortimento_tipo_vinculo', null);
    }

    public function test_mix_nao_pode_seguir_escolha_sem_o_formulario_pedir_escolha(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto Extra',
            'campos' => [[
                'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO',
                'sortimento_origem' => 'DINAMICO', 'sortimento_segue_escolha' => true,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.sortimento_segue_escolha');
    }

    public function test_update_parcial_mantem_a_escolha_ja_salva_ao_validar_o_mix(): void
    {
        $tipo = $this->formularioPontoExtra();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->putJson("/api/tipos-registro/{$tipo->uuid}", [
            'campos' => [[
                'chave' => 'mix', 'rotulo' => 'Mix do ponto extra', 'tipo_campo' => 'SORTIMENTO',
                'sortimento_origem' => 'DINAMICO', 'sortimento_segue_escolha' => true,
            ]],
        ])->assertOk();

        $this->putJson("/api/tipos-registro/{$tipo->uuid}", [
            'escolhe_departamento' => false,
            'escolhe_secao' => false,
            'campos' => [[
                'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO',
                'sortimento_origem' => 'DINAMICO', 'sortimento_segue_escolha' => true,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.sortimento_segue_escolha');
    }

    public function test_registro_exige_um_dos_recortes_liberados_pelo_formulario(): void
    {
        $tipo = $this->formularioPontoExtra(['escolhe_secao' => true]);
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Brisa']);
        $visita = $this->abrirVisita();

        $this->registrar($visita, $tipo, [], ['presentes' => [], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_vinculo');

        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'MARCA', 'marca_uuid' => $marca->uuid], ['presentes' => [], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_vinculo');
    }

    public function test_registro_valida_o_mix_contra_a_secao_escolhida(): void
    {
        $this->noMix('DEPARTAMENTO', ['departamento_id' => $this->limpeza->id]);
        $tipo = $this->formularioPontoExtra();
        $visita = $this->abrirVisita();
        $naSecao = ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->amaciante->uuid];

        $this->registrar($visita, $tipo, $naSecao, ['presentes' => [$this->amacianteAzul->uuid], 'ausentes' => [$this->amacianteRosa->uuid]])
            ->assertCreated();

        // Sabão está no mix da loja (departamento inteiro), mas não na seção escolhida.
        $this->registrar($visita, $tipo, $naSecao, ['presentes' => [$this->sabaoPo->uuid], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');

        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'DEPARTAMENTO', 'departamento_uuid' => $this->limpeza->uuid], [
            'presentes' => [$this->amacianteAzul->uuid, $this->sabaoPo->uuid], 'ausentes' => [],
        ])->assertCreated();
    }

    public function test_produto_fora_do_mix_so_entra_se_estiver_no_recorte_escolhido(): void
    {
        $this->noMix('PRODUTO', ['produto_id' => $this->amacianteAzul->id]);
        $tipo = $this->formularioPontoExtra();
        $visita = $this->abrirVisita();
        $naSecao = ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->amaciante->uuid];

        $registro = $this->registrar($visita, $tipo, $naSecao, [
            'presentes' => [$this->amacianteAzul->uuid, $this->amacianteRosa->uuid], 'ausentes' => [],
            'fora_do_mix' => [$this->amacianteRosa->uuid],
        ])->assertCreated();

        $presentes = collect(collect($registro->json('registro.campos_respondidos'))->firstWhere('chave', 'mix')['sortimento']['presentes']);
        $this->assertTrue($presentes->firstWhere('id', $this->amacianteRosa->uuid)['fora_do_mix']);
        $this->assertFalse($presentes->firstWhere('id', $this->amacianteAzul->uuid)['fora_do_mix']);

        // Rosa sem estar marcado como fora do mix: não está no mix da loja.
        $this->registrar($visita, $tipo, $naSecao, ['presentes' => [$this->amacianteRosa->uuid], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');

        // Sabão é de outra seção: nem como fora do mix.
        $this->registrar($visita, $tipo, $naSecao, [
            'presentes' => [$this->sabaoPo->uuid], 'ausentes' => [], 'fora_do_mix' => [$this->sabaoPo->uuid],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');

        // Fora do mix precisa estar entre os presentes.
        $this->registrar($visita, $tipo, $naSecao, [
            'presentes' => [], 'ausentes' => [$this->amacianteRosa->uuid], 'fora_do_mix' => [$this->amacianteRosa->uuid],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');
    }
}
