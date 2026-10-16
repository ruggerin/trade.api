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
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/82 — "Onde é o registro?": marca pelo produto (§5.3), filtro de marca depois da
 * seção/departamento (§5.2) e "só o que a loja tem" (§5.1).
 */
class Doc82OndeERegistroTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private DepartamentoAuditoria $biscoitos;

    private SecaoAuditoria $recheado;

    private SecaoAuditoria $cream;

    private MarcaAuditoria $piraque;

    private MarcaAuditoria $outra;

    private ProdutoAuditoria $recheadoPiraque;

    private ProdutoAuditoria $recheadoOutra;

    private ProdutoAuditoria $creamPiraque;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->biscoitos = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Biscoitos']);
        $this->recheado = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Recheado', 'departamento_id' => $this->biscoitos->id]);
        $this->cream = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Cream cracker', 'departamento_id' => $this->biscoitos->id]);
        $this->piraque = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Piraquê']);
        $this->outra = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Outra']);
        // Departamento compartilhado: o vínculo antigo (MarcaDepartamento) trazia as duas marcas juntas.
        MarcaDepartamento::create(['marca_id' => $this->piraque->id, 'departamento_id' => $this->biscoitos->id]);
        MarcaDepartamento::create(['marca_id' => $this->outra->id, 'departamento_id' => $this->biscoitos->id]);

        $produto = fn (string $descricao, SecaoAuditoria $secao, MarcaAuditoria $marca) => ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'descricao' => $descricao,
            'secao_id' => $secao->id, 'departamento_id' => $this->biscoitos->id, 'marca_id' => $marca->id,
        ]);
        $this->recheadoPiraque = $produto('Recheado Piraquê', $this->recheado, $this->piraque);
        $this->recheadoOutra = $produto('Recheado Outra', $this->recheado, $this->outra);
        $this->creamPiraque = $produto('Cream cracker Piraquê', $this->cream, $this->piraque);
    }

    private function noMix(string $tipoItem, array $colunas): void
    {
        SortimentoPontoVenda::create(['ponto_venda_id' => $this->pdv->id, 'tipo_item' => $tipoItem, ...$colunas]);
    }

    private function formulario(array $flags): TipoRegistro
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

    private function registrar(string $visita, TipoRegistro $tipo, array $vinculo, array $mix)
    {
        return $this->postJson("/api/visitas/{$visita}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) Str::uuid(),
            ...$vinculo,
            'valores_campos' => ['mix' => json_encode($mix)],
        ]);
    }

    // ---- §5.3 marca pelo produto ----

    public function test_checklist_por_marca_traz_so_os_produtos_daquela_marca(): void
    {
        $this->noMix('DEPARTAMENTO', ['departamento_id' => $this->biscoitos->id]);
        $tipo = $this->formulario(['escolhe_marca' => true]);
        $visita = $this->abrirVisita();

        // Produto da OUTRA marca no mesmo departamento: recusado.
        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'MARCA', 'marca_uuid' => $this->piraque->uuid],
            ['presentes' => [$this->recheadoOutra->uuid], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');

        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'MARCA', 'marca_uuid' => $this->piraque->uuid],
            ['presentes' => [$this->recheadoPiraque->uuid], 'ausentes' => [$this->creamPiraque->uuid]])
            ->assertCreated();
    }

    public function test_item_marca_inteira_do_mix_vale_pelos_produtos_da_marca(): void
    {
        $this->noMix('MARCA', ['marca_id' => $this->piraque->id]);
        $campo = $this->formulario(['escolhe_marca' => true])->campos()->first();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $resposta = $this->getJson("/api/tipos-registro/campos/{$campo->uuid}/sortimento?ponto_venda_uuid={$this->pdv->uuid}")->assertOk();

        $this->assertEqualsCanonicalizing([$this->recheadoPiraque->uuid, $this->creamPiraque->uuid], $resposta->json('produtos.*.produto_uuid'));
        $this->assertSame($this->piraque->uuid, $resposta->json('produtos.0.marca_uuid'));
        $this->assertSame([$this->piraque->uuid], $resposta->json('recortes.marcas.*.uuid'));
    }

    public function test_fora_do_mix_por_marca_aceita_produto_da_marca_e_recusa_de_outra(): void
    {
        $this->noMix('PRODUTO', ['produto_id' => $this->recheadoPiraque->id]);
        $tipo = $this->formulario(['escolhe_marca' => true]);
        $visita = $this->abrirVisita();

        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'MARCA', 'marca_uuid' => $this->piraque->uuid],
            ['presentes' => [$this->creamPiraque->uuid], 'ausentes' => [], 'fora_do_mix' => [$this->creamPiraque->uuid]])
            ->assertCreated();
        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'MARCA', 'marca_uuid' => $this->piraque->uuid],
            ['presentes' => [$this->recheadoOutra->uuid], 'ausentes' => [], 'fora_do_mix' => [$this->recheadoOutra->uuid]])
            ->assertStatus(422);
    }

    // ---- §5.2 marca depois da seção ----

    public function test_secao_com_marca_filtra_o_checklist_e_grava_a_marca_como_complemento(): void
    {
        $this->noMix('SECAO', ['secao_id' => $this->recheado->id]);
        $tipo = $this->formulario(['escolhe_secao' => true, 'filtra_marca' => true]);
        $visita = $this->abrirVisita();
        $vinculo = ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->recheado->uuid, 'marca_uuid' => $this->piraque->uuid];

        // Recheado da Outra não está em Recheado ∩ Piraquê.
        $this->registrar($visita, $tipo, $vinculo, ['presentes' => [$this->recheadoOutra->uuid], 'ausentes' => []])->assertStatus(422);

        $this->registrar($visita, $tipo, $vinculo, ['presentes' => [$this->recheadoPiraque->uuid], 'ausentes' => []])->assertCreated();
        $registro = VisitaRegistro::latest('id')->firstOrFail();
        $this->assertSame($this->recheado->id, $registro->secao_id);
        $this->assertSame($this->piraque->id, $registro->marca_id);
        $this->assertSame('SECAO', $registro->tipo_vinculo?->value ?? $registro->tipo_vinculo);
    }

    public function test_sem_filtra_marca_a_marca_junto_da_secao_e_ignorada(): void
    {
        $this->noMix('SECAO', ['secao_id' => $this->recheado->id]);
        $tipo = $this->formulario(['escolhe_secao' => true]);
        $visita = $this->abrirVisita();

        // Sem o filtro, vale a seção inteira: o recheado da Outra entra e a marca não é gravada.
        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->recheado->uuid, 'marca_uuid' => $this->piraque->uuid],
            ['presentes' => [$this->recheadoOutra->uuid], 'ausentes' => [$this->recheadoPiraque->uuid]])
            ->assertCreated();
        $this->assertNull(VisitaRegistro::latest('id')->value('marca_id'));
    }

    // ---- §5.1 só o que a loja tem ----

    public function test_so_da_loja_recusa_recorte_fora_do_mix(): void
    {
        $this->noMix('PRODUTO', ['produto_id' => $this->recheadoPiraque->id]);
        $tipo = $this->formulario(['escolhe_secao' => true, 'escolhe_departamento' => true, 'lista_recorte' => 'SO_DA_LOJA']);
        $visita = $this->abrirVisita();

        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->cream->uuid],
            ['presentes' => [], 'ausentes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_vinculo');

        // Seção do produto do mix e o departamento dela estão cobertos.
        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'SECAO', 'secao_uuid' => $this->recheado->uuid],
            ['presentes' => [$this->recheadoPiraque->uuid], 'ausentes' => []])->assertCreated();
        $this->registrar($visita, $tipo, ['tipo_vinculo' => 'DEPARTAMENTO', 'departamento_uuid' => $this->biscoitos->uuid],
            ['presentes' => [$this->recheadoPiraque->uuid], 'ausentes' => []])->assertCreated();
    }

    public function test_so_da_loja_com_mix_vazio_nao_trava(): void
    {
        $tipo = TipoRegistro::create([
            'empresa_id' => $this->empresa->id, 'descricao' => 'Levantamento', 'granularidade_padrao' => 'LINHA',
            'escolhe_secao' => true, 'lista_recorte' => 'SO_DA_LOJA',
        ]);
        $visita = $this->abrirVisita();

        $this->postJson("/api/visitas/{$visita}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) Str::uuid(),
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $this->cream->uuid,
        ])->assertCreated();
    }

    // ---- cadastro ----

    public function test_admin_salva_atualiza_e_duplica_lista_e_filtro_de_marca(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $id = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto Extra', 'escolhe_secao' => true, 'filtra_marca' => true,
        ])->assertCreated()
            ->assertJsonPath('tipo_registro.lista_recorte', 'PRIMEIRO_DA_LOJA')
            ->assertJsonPath('tipo_registro.filtra_marca', true)
            ->json('tipo_registro.id');

        $this->putJson("/api/tipos-registro/{$id}", ['lista_recorte' => 'SO_DA_LOJA'])->assertOk()
            ->assertJsonPath('tipo_registro.lista_recorte', 'SO_DA_LOJA');
        $this->putJson("/api/tipos-registro/{$id}", ['lista_recorte' => 'QUALQUER'])->assertStatus(422);

        $copia = TipoRegistro::where('uuid', '!=', $id)->latest('id');
        $this->postJson("/api/tipos-registro/{$id}/duplicar")->assertSuccessful();
        $this->assertSame('SO_DA_LOJA', $copia->value('lista_recorte'));
        $this->assertTrue((bool) $copia->value('filtra_marca'));
    }
}
