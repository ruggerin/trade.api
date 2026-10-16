<?php

namespace Tests\Feature;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RamoAtividade;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/80-MONITORAMENTO-LINHA-AUSENTE.md — escopo de Ação LINHA_AUSENTE (a Ação vale onde nenhum
 * item aprovado do Mix cobre o recorte; Mix vazio não cobra) e filtro por ramo de atividade.
 */
class Doc80LinhaAusenteTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private DepartamentoAuditoria $snacks;

    private SecaoAuditoria $batataPalha;

    private SecaoAuditoria $salgadinho;

    private ProdutoAuditoria $batata;

    private ProdutoAuditoria $chips;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->snacks = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Extrusados & Snacks']);
        $this->batataPalha = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Batatas Palha', 'departamento_id' => $this->snacks->id]);
        $this->salgadinho = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Salgadinhos', 'departamento_id' => $this->snacks->id]);
        $this->batata = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'secao_id' => $this->batataPalha->id, 'departamento_id' => $this->snacks->id,
        ]);
        $this->chips = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'secao_id' => $this->salgadinho->id, 'departamento_id' => $this->snacks->id,
        ]);
    }

    private function payloadLinhaAusente(array $extra = []): array
    {
        return [
            'descricao' => 'Linha ausente: Batatas Palha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'LINHA_AUSENTE',
            'escopo_tipo_vinculo' => 'SECAO',
            'escopo_secao_uuid' => $this->batataPalha->uuid,
            ...$extra,
        ];
    }

    private function loja(string $fantasia, array $mix = [], ?RamoAtividade $ramo = null): PontoVenda
    {
        $pdv = PontoVenda::factory()->create([
            'empresa_id' => $this->empresa->id, 'fantasia' => $fantasia, 'ramo_atividade_id' => $ramo?->id,
        ]);
        foreach ($mix as $item) {
            SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, ...$item]);
        }

        return $pdv;
    }

    public function test_cria_formulario_de_linha_ausente_com_recorte_e_ramos(): void
    {
        $supermercado = RamoAtividade::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Supermercado']);

        $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['ramos_atividade_uuids' => [$supermercado->uuid]]))
            ->assertCreated()
            ->assertJsonPath('tipo_registro.escopo_acao', 'LINHA_AUSENTE')
            ->assertJsonPath('tipo_registro.escopo_tipo_vinculo', 'SECAO')
            ->assertJsonPath('tipo_registro.escopo_secao.id', $this->batataPalha->uuid)
            ->assertJsonPath('tipo_registro.escopo_secao.departamento_uuid', $this->snacks->uuid)
            ->assertJsonPath('tipo_registro.ramos_atividade_uuids', [$supermercado->uuid]);
    }

    public function test_linha_ausente_exige_o_recorte(): void
    {
        $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['escopo_tipo_vinculo' => null, 'escopo_secao_uuid' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('escopo_tipo_vinculo');

        $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['escopo_secao_uuid' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('escopo_secao_uuid');
    }

    public function test_sair_do_escopo_limpa_o_recorte_e_desligar_a_acao_limpa_os_ramos(): void
    {
        $supermercado = RamoAtividade::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Supermercado']);
        $uuid = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['ramos_atividade_uuids' => [$supermercado->uuid]]))
            ->json('tipo_registro.id');

        $this->putJson("/api/tipos-registro/{$uuid}", ['escopo_acao' => 'SEMPRE'])
            ->assertOk()
            ->assertJsonPath('tipo_registro.escopo_tipo_vinculo', null)
            ->assertJsonPath('tipo_registro.escopo_secao', null)
            ->assertJsonPath('tipo_registro.ramos_atividade_uuids', [$supermercado->uuid]);

        $this->putJson("/api/tipos-registro/{$uuid}", ['acao_obrigatoria' => false])
            ->assertOk()
            ->assertJsonPath('tipo_registro.ramos_atividade_uuids', []);
    }

    public function test_lojas_da_acao_sao_as_que_tem_mix_sem_a_linha_e_passam_no_ramo(): void
    {
        $supermercado = RamoAtividade::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Supermercado']);
        $drogaria = RamoAtividade::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Drogaria']);

        $this->loja('A mix vazio');
        $this->loja('B tem batata', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id]]);
        $this->loja('C só chips', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id]], $supermercado);
        $this->loja('D batata pendente', [
            ['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id],
            ['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id, 'status_aprovacao' => 'PENDENTE'],
        ]);
        $this->loja('E departamento inteiro', [['tipo_item' => 'DEPARTAMENTO', 'departamento_id' => $this->snacks->id]]);
        $this->loja('F seção batata inteira', [['tipo_item' => 'SECAO', 'secao_id' => $this->batataPalha->id]]);
        $this->loja('G drogaria só chips', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id]], $drogaria);

        $uuid = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['ramos_atividade_uuids' => [$supermercado->uuid]]))
            ->json('tipo_registro.id');

        $fantasias = collect($this->getJson("/api/tipos-registro/{$uuid}/lojas-da-acao")->assertOk()->json('pontos_venda'))->pluck('fantasia')->all();

        // Loja sem ramo cadastrado passa no filtro; drogaria não.
        $this->assertSame(['C só chips', 'D batata pendente'], $fantasias);
    }

    public function test_recorte_por_departamento_e_por_marca(): void
    {
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->batata->update(['marca_id' => $marca->id]);
        $outroDepartamento = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Limpeza']);
        $amaciante = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'departamento_id' => $outroDepartamento->id]);

        $this->loja('Só limpeza', [['tipo_item' => 'PRODUTO', 'produto_id' => $amaciante->id]]);
        $this->loja('Seção de snacks', [['tipo_item' => 'SECAO', 'secao_id' => $this->salgadinho->id]]);
        $this->loja('Batata da marca', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id]]);

        $porDepartamento = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente([
            'escopo_tipo_vinculo' => 'DEPARTAMENTO', 'escopo_secao_uuid' => null, 'escopo_departamento_uuid' => $this->snacks->uuid,
        ]))->json('tipo_registro.id');
        $this->assertSame(['Só limpeza'], collect($this->getJson("/api/tipos-registro/{$porDepartamento}/lojas-da-acao")->json('pontos_venda'))->pluck('fantasia')->all());

        $porMarca = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente([
            'descricao' => 'Linha ausente: marca', 'escopo_tipo_vinculo' => 'MARCA', 'escopo_secao_uuid' => null, 'escopo_marca_uuid' => $marca->uuid,
        ]))->json('tipo_registro.id');
        $this->assertSame(['Seção de snacks', 'Só limpeza'], collect($this->getJson("/api/tipos-registro/{$porMarca}/lojas-da-acao")->json('pontos_venda'))->pluck('fantasia')->all());
    }

    public function test_mix_do_pdv_traz_marca_do_produto_e_departamento_da_secao(): void
    {
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->batata->update(['marca_id' => $marca->id]);
        $pdv = $this->loja('Loja', [
            ['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id],
            ['tipo_item' => 'SECAO', 'secao_id' => $this->salgadinho->id],
        ]);

        $sortimento = collect($this->getJson("/api/pontos-venda/{$pdv->uuid}")->assertOk()->json('ponto_venda.sortimento'));

        $this->assertSame($marca->uuid, $sortimento->firstWhere('tipo_item', 'PRODUTO')['produto']['marca_uuid']);
        $this->assertSame($this->snacks->uuid, $sortimento->firstWhere('tipo_item', 'SECAO')['secao']['departamento_uuid']);
    }

    // §5.2 — intervalo entre cobranças: o detalhe da loja traz o último registro de cada formulário
    // obrigatório com intervalo, pro app decidir offline.
    public function test_intervalo_dias_e_ultimo_registro_por_formulario_no_detalhe_da_loja(): void
    {
        $uuid = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente(['intervalo_dias' => 7]))
            ->assertCreated()
            ->assertJsonPath('tipo_registro.intervalo_dias', 7)
            ->json('tipo_registro.id');
        $semIntervalo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Sempre', 'acao_obrigatoria' => true, 'escopo_acao' => 'SEMPRE']);
        $pdv = $this->loja('Loja');
        $tipo = TipoRegistro::where('uuid', $uuid)->firstOrFail();

        $this->getJson("/api/pontos-venda/{$pdv->uuid}")->assertOk()->assertJsonPath('ultimos_registros_formulario', []);

        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $pdv->id,
            'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id])->id,
            'inicio_data' => now()->subDays(3),
        ]);
        $antigo = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id]);
        $antigo->created_at = now()->subDays(3);
        $antigo->save();
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'cancelado_em' => now()]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $semIntervalo->id]);

        $ultimos = $this->getJson("/api/pontos-venda/{$pdv->uuid}")->assertOk()->json('ultimos_registros_formulario');
        $this->assertSame([$uuid], array_keys($ultimos));
        $this->assertSame(now()->subDays(3)->startOfSecond()->toIso8601String(), Carbon::parse($ultimos[$uuid])->toIso8601String());

        $this->putJson("/api/tipos-registro/{$uuid}", ['intervalo_dias' => -1])->assertStatus(422)->assertJsonValidationErrors('intervalo_dias');
    }

    // §5.3 — conversão: das lojas levantadas, quantas passaram a ter a linha no Mix no período,
    // depois do primeiro levantamento.
    public function test_conversao_conta_lojas_em_que_a_linha_entrou_depois_do_levantamento(): void
    {
        $uuid = $this->postJson('/api/tipos-registro', $this->payloadLinhaAusente())->json('tipo_registro.id');
        $tipo = TipoRegistro::where('uuid', $uuid)->firstOrFail();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);

        $levantar = function (PontoVenda $pdv, int $diasAtras) use ($tipo, $promotor): void {
            $visita = Visita::factory()->create([
                'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
                'inicio_data' => now()->subDays($diasAtras),
            ]);
            $registro = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id]);
            $registro->created_at = now()->subDays($diasAtras);
            $registro->save();
        };
        $entrouNoMix = function (PontoVenda $pdv, array $item, int $diasAtras): void {
            $novo = SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, ...$item]);
            $novo->created_at = now()->subDays($diasAtras);
            $novo->save();
        };

        $convertida = $this->loja('Convertida', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id]]);
        $levantar($convertida, 20);
        $entrouNoMix($convertida, ['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id], 5);

        $pendente = $this->loja('Só pendente', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id]]);
        $levantar($pendente, 20);
        $entrouNoMix($pendente, ['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id, 'status_aprovacao' => 'PENDENTE'], 5);

        $antiga = $this->loja('Entrou antes do período', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->chips->id]]);
        $levantar($antiga, 40);
        $entrouNoMix($antiga, ['tipo_item' => 'SECAO', 'secao_id' => $this->batataPalha->id], 30);

        $this->loja('Nunca levantada', [['tipo_item' => 'PRODUTO', 'produto_id' => $this->batata->id]]);

        $inicio = now()->subDays(10)->toDateString();
        $fim = now()->toDateString();
        $this->getJson("/api/tipos-registro/{$uuid}/conversao?inicio={$inicio}&fim={$fim}")
            ->assertOk()
            ->assertJsonPath('monitoradas', 3)
            ->assertJsonPath('convertidas', 1)
            ->assertJsonPath('lojas_convertidas.0.fantasia', 'Convertida');

        $outro = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Sempre', 'acao_obrigatoria' => true, 'escopo_acao' => 'SEMPRE']);
        $this->getJson("/api/tipos-registro/{$outro->uuid}/conversao?inicio={$inicio}&fim={$fim}")->assertStatus(422);
    }
}
