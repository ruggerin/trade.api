<?php

namespace Tests\Feature;

use App\Enums\Propriedade;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/85 — concorrentes por marca e embalagem: cadastro do formulário (lista de marcas, marca
 * nova, várias embalagens, lista guiada pelo Mix da loja), registro com embalagem, marca nova
 * offline "a aprovar", visão com R$/litro, aprovar e juntar marcas.
 */
class Doc85ConcorrentesPorMarcaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $promotor;

    private MarcaAuditoria $downy;

    private MarcaAuditoria $brinorte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->downy = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Downy', 'propriedade' => Propriedade::CONCORRENTE]);
        $this->brinorte = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Brinorte', 'propriedade' => Propriedade::CONCORRENTE]);
    }

    private function formularioPorMarca(array $config = []): TipoRegistro
    {
        $tipo = TipoRegistro::create([
            'empresa_id' => $this->empresa->id, 'descricao' => 'Concorrentes Amaciante',
            'granularidade_padrao' => 'MARCA', ...$config,
        ]);
        $tipo->marcasPredefinidas()->sync([$this->downy->id => ['ordem' => 0], $this->brinorte->id => ['ordem' => 1]]);
        foreach ([['preco', 'Preço na gôndola', 'MOEDA'], ['promocao', 'Está em promoção?', 'BOOLEANO'], ['frentes', 'Quantas frentes?', 'NUMERO']] as $i => [$chave, $rotulo, $tipoCampo]) {
            CampoTipoRegistro::create(['tipo_registro_id' => $tipo->id, 'chave' => $chave, 'rotulo' => $rotulo, 'tipo_campo' => $tipoCampo, 'ordem' => $i]);
        }

        return $tipo;
    }

    private function visita(?PontoVenda $pdv = null): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => ($pdv ?? PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]))->id,
            'usuario_id' => $this->promotor->id,
        ]);
    }

    private function registrar(Visita $visita, TipoRegistro $tipo, array $dados)
    {
        Sanctum::actingAs($this->promotor);

        return $this->postJson("/api/visitas/{$visita->uuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) Str::uuid(),
            ...$dados,
        ], ['X-Canal' => 'mobile']);
    }

    // ---- cadastro do formulário ----

    public function test_admin_cadastra_formulario_por_marca_com_lista_e_opcoes(): void
    {
        Sanctum::actingAs($this->admin);

        $r = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Concorrentes Amaciante',
            'granularidade_padrao' => 'MARCA',
            'marcas_uuids' => [$this->brinorte->uuid, $this->downy->uuid],
            'permite_marca_nova' => false,
            'varias_embalagens' => false,
            'campos' => [['chave' => 'preco', 'rotulo' => 'Preço', 'tipo_campo' => 'MOEDA']],
        ])->assertCreated()
            ->assertJsonPath('tipo_registro.granularidade_padrao', 'MARCA')
            ->assertJsonPath('tipo_registro.permite_marca_nova', false)
            ->assertJsonPath('tipo_registro.varias_embalagens', false)
            // A ordem escolhida é a ordem no app.
            ->assertJsonPath('tipo_registro.marcas_predefinidas.0.descricao', 'Brinorte')
            ->assertJsonPath('tipo_registro.marcas_predefinidas.1.descricao', 'Downy');

        // Sair de MARCA limpa a lista.
        $this->putJson('/api/tipos-registro/'.$r->json('tipo_registro.id'), ['granularidade_padrao' => 'LINHA'])
            ->assertOk()->assertJsonCount(0, 'tipo_registro.marcas_predefinidas');
    }

    public function test_lista_so_aceita_concorrente_e_precisa_de_marca_ou_marca_nova(): void
    {
        Sanctum::actingAs($this->admin);
        $nossa = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Nossa Marca']);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'X', 'granularidade_padrao' => 'MARCA', 'marcas_uuids' => [$nossa->uuid],
        ])->assertStatus(422)->assertJsonValidationErrors('marcas_uuids');

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'X', 'granularidade_padrao' => 'MARCA', 'marcas_uuids' => [], 'permite_marca_nova' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('marcas_uuids');

        // Exceção por seção não vira MARCA.
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->postJson('/api/tipos-registro', [
            'descricao' => 'X', 'granularidade_padrao' => 'LINHA',
            'excecoes_granularidade' => [['secao_uuid' => $secao->uuid, 'granularidade' => 'MARCA']],
        ])->assertStatus(422)->assertJsonValidationErrors('excecoes_granularidade.0.granularidade');
    }

    public function test_cada_produto_pode_usar_o_mix_da_loja_e_a_lista_fixa_some(): void
    {
        Sanctum::actingAs($this->admin);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);

        $id = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Presença', 'granularidade_padrao' => 'PRODUTO', 'produtos_uuids' => [$produto->uuid],
        ])->assertCreated()
            ->assertJsonPath('tipo_registro.lista_produtos_origem', 'FIXA')
            ->assertJsonCount(1, 'tipo_registro.produtos_predefinidos')
            ->json('tipo_registro.id');

        $this->putJson("/api/tipos-registro/{$id}", ['lista_produtos_origem' => 'MIX_LOJA'])->assertOk()
            ->assertJsonPath('tipo_registro.lista_produtos_origem', 'MIX_LOJA')
            ->assertJsonCount(0, 'tipo_registro.produtos_predefinidos');

        $this->putJson("/api/tipos-registro/{$id}", ['lista_produtos_origem' => 'OUTRA'])
            ->assertStatus(422)->assertJsonValidationErrors('lista_produtos_origem');
    }

    // ---- registro ----

    public function test_registro_exige_marca_e_embalagem_e_grava_o_vinculo_do_escopo(): void
    {
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Amaciante']);
        $tipo = $this->formularioPorMarca([
            'acao_obrigatoria' => true, 'escopo_acao' => 'LINHA_AUSENTE',
            'escopo_tipo_vinculo' => 'SECAO', 'escopo_secao_id' => $secao->id,
        ]);
        $visita = $this->visita();

        $this->registrar($visita, $tipo, ['valores_campos' => ['preco' => '12']])
            ->assertStatus(422)->assertJsonValidationErrors('marca_uuid');
        $this->registrar($visita, $tipo, ['marca_uuid' => $this->downy->uuid, 'valores_campos' => ['preco' => '12']])
            ->assertStatus(422)->assertJsonValidationErrors('embalagem_quantidade');
        $this->registrar($visita, $tipo, ['marca_uuid' => $this->downy->uuid, 'embalagem_quantidade' => 500, 'embalagem_unidade' => 'XX'])
            ->assertStatus(422)->assertJsonValidationErrors('embalagem_unidade');

        $this->registrar($visita, $tipo, [
            'marca_uuid' => $this->downy->uuid, 'embalagem_quantidade' => 500, 'embalagem_unidade' => 'ML',
            'valores_campos' => ['preco' => '12'],
        ])->assertCreated()
            ->assertJsonPath('registro.marca.descricao', 'Downy')
            ->assertJsonPath('registro.secao.descricao', 'Amaciante')
            ->assertJsonPath('registro.tipo_vinculo', 'SECAO')
            ->assertJsonPath('registro.embalagem.descricao', '500 ml');
    }

    public function test_marca_nova_offline_nasce_a_aprovar_e_reenvio_nao_duplica(): void
    {
        $tipo = $this->formularioPorMarca();
        $visita = $this->visita();
        $uuidNoCelular = (string) Str::uuid();
        $chave = (string) Str::uuid();
        Sanctum::actingAs($this->promotor);
        $corpo = [
            'tipo_registro_uuid' => $tipo->uuid, 'idempotency_key' => $chave,
            'marca_uuid' => $uuidNoCelular, 'marca_nova_descricao' => 'Ypê Amaciante',
            'embalagem_quantidade' => 2, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '9.90'],
        ];

        $this->postJson("/api/visitas/{$visita->uuid}/registros", $corpo, ['X-Canal' => 'mobile'])->assertCreated()
            ->assertJsonPath('registro.marca.id', $uuidNoCelular);
        $this->postJson("/api/visitas/{$visita->uuid}/registros", $corpo, ['X-Canal' => 'mobile'])->assertOk();

        $marca = MarcaAuditoria::where('uuid', $uuidNoCelular)->sole();
        $this->assertSame('PENDENTE', $marca->status_aprovacao->value);
        $this->assertSame($this->promotor->id, $marca->criado_por_usuario_id);
        $this->assertSame(Propriedade::CONCORRENTE, $marca->propriedade);
        $this->assertSame(1, VisitaRegistro::count());

        // Outro celular cadastra o mesmo nome (com outra caixa): reaproveita a marca.
        $this->registrar($this->visita(), $tipo, [
            'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'YPÊ AMACIANTE',
            'embalagem_quantidade' => 1, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '5'],
        ])->assertCreated()->assertJsonPath('registro.marca.id', $uuidNoCelular);
        $this->assertSame(1, MarcaAuditoria::where('descricao', 'ilike', 'ypê amaciante')->count());
    }

    public function test_formulario_fechado_recusa_marca_fora_da_lista_e_segunda_embalagem(): void
    {
        $tipo = $this->formularioPorMarca(['permite_marca_nova' => false, 'varias_embalagens' => false]);
        $visita = $this->visita();

        $this->registrar($visita, $tipo, [
            'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'Comfort',
            'embalagem_quantidade' => 1, 'embalagem_unidade' => 'L',
        ])->assertStatus(422)->assertJsonValidationErrors('marca_uuid');
        $this->assertSame(0, MarcaAuditoria::where('descricao', 'Comfort')->count());

        $embalagem = ['marca_uuid' => $this->downy->uuid, 'embalagem_unidade' => 'ML', 'valores_campos' => ['preco' => '12']];
        $this->registrar($visita, $tipo, [...$embalagem, 'embalagem_quantidade' => 500])->assertCreated();
        $this->registrar($visita, $tipo, [...$embalagem, 'embalagem_quantidade' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('embalagem_quantidade');
        // Outra marca na mesma visita, tudo bem.
        $this->registrar($visita, $tipo, [...$embalagem, 'marca_uuid' => $this->brinorte->uuid, 'embalagem_quantidade' => 2, 'embalagem_unidade' => 'L'])
            ->assertCreated();
    }

    // ---- visão da indústria ----

    public function test_visao_quem_ocupa_o_espaco_calcula_preco_por_litro_do_mais_barato(): void
    {
        $tipo = $this->formularioPorMarca();
        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $pdv2 = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        // Downy 500 ml a R$ 12,00 e R$ 14,00 (média 13 → R$ 26/L), Brinorte 2 L a R$ 5,99 (R$ 3/L).
        $this->registrar($this->visita($pdv1), $tipo, ['marca_uuid' => $this->downy->uuid, 'embalagem_quantidade' => 500, 'embalagem_unidade' => 'ML', 'valores_campos' => ['preco' => '12', 'promocao' => '1', 'frentes' => '2']])->assertCreated();
        $this->registrar($this->visita($pdv2), $tipo, ['marca_uuid' => $this->downy->uuid, 'embalagem_quantidade' => 500, 'embalagem_unidade' => 'ML', 'valores_campos' => ['preco' => '14', 'promocao' => '0', 'frentes' => '4']])->assertCreated();
        $this->registrar($this->visita($pdv1), $tipo, ['marca_uuid' => $this->brinorte->uuid, 'embalagem_quantidade' => 2, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '5.99']])->assertCreated();

        Sanctum::actingAs($this->admin);
        $r = $this->getJson("/api/tipos-registro/{$tipo->uuid}/concorrentes?inicio=".now()->subDay()->toDateString().'&fim='.now()->toDateString())
            ->assertOk();

        $this->assertSame(['Brinorte', 'Downy'], array_column(array_column($r->json('linhas'), 'marca'), 'descricao'));
        $this->assertEquals(3.0, $r->json('linhas.0.preco_por_referencia'));
        $this->assertSame('L', $r->json('linhas.0.referencia'));
        $this->assertSame('2 L', $r->json('linhas.0.embalagem'));
        $this->assertEquals(13.0, $r->json('linhas.1.preco_medio'));
        $this->assertEquals(26.0, $r->json('linhas.1.preco_por_referencia'));
        $this->assertSame(2, $r->json('linhas.1.lojas'));
        $this->assertSame([['rotulo' => 'Está em promoção?', 'valor' => '50% sim'], ['rotulo' => 'Quantas frentes?', 'valor' => '3']], $r->json('linhas.1.circunstancias'));
        $this->assertSame('Brinorte', $r->json('destaques.mais_barato.marca.descricao'));
        $this->assertSame(2, $r->json('destaques.total_marcas'));

        // Formulário que não é por marca não tem essa visão.
        $outro = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Outro']);
        $this->getJson("/api/tipos-registro/{$outro->uuid}/concorrentes?inicio=2026-01-01&fim=2026-12-31")->assertStatus(422);
    }

    // ---- marca a aprovar ----

    public function test_admin_aprova_ou_junta_a_marca_do_promotor(): void
    {
        $tipo = $this->formularioPorMarca();
        $this->registrar($this->visita(), $tipo, [
            'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'DOWNY CONCENTRADO',
            'embalagem_quantidade' => 1, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '20'],
        ])->assertCreated();
        $this->registrar($this->visita(), $tipo, [
            'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'Comfort',
            'embalagem_quantidade' => 1, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '18'],
        ])->assertCreated();
        $duplicada = MarcaAuditoria::where('descricao', 'DOWNY CONCENTRADO')->sole();
        $comfort = MarcaAuditoria::where('descricao', 'Comfort')->sole();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/marcas-auditoria?a_aprovar=1')->assertOk()->assertJsonCount(2, 'marcas')
            ->assertJsonPath('marcas.0.a_aprovar', true)
            ->assertJsonPath('marcas.0.criado_por.nome', $this->promotor->nome);

        $this->postJson("/api/marcas-auditoria/{$comfort->uuid}/aprovar")->assertOk()->assertJsonPath('marca.a_aprovar', false);
        $this->postJson("/api/marcas-auditoria/{$comfort->uuid}/aprovar")->assertStatus(422);

        $this->postJson("/api/marcas-auditoria/{$duplicada->uuid}/juntar", ['destino_uuid' => $duplicada->uuid])->assertStatus(422);
        $this->postJson("/api/marcas-auditoria/{$duplicada->uuid}/juntar", ['destino_uuid' => $this->downy->uuid])->assertOk();

        $this->assertSame(0, VisitaRegistro::where('marca_id', $duplicada->id)->count());
        $this->assertSame(1, VisitaRegistro::where('marca_id', $this->downy->id)->count());
        $this->assertFalse($duplicada->fresh()->ativo);
        $this->getJson('/api/marcas-auditoria?a_aprovar=1')->assertJsonCount(0, 'marcas');
        $this->assertNotContains('DOWNY CONCENTRADO', array_column($this->getJson('/api/marcas-auditoria?por_pagina=100')->json('marcas'), 'descricao'));

        // Promotor não aprova.
        Sanctum::actingAs($this->promotor);
        $this->postJson("/api/marcas-auditoria/{$comfort->uuid}/aprovar")->assertForbidden();
    }
}
