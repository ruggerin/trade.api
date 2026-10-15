<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\ValoresDoPedido;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Valor e faturamento no pedido e Esforço × Retorno (docs/61): regras de preenchimento, gate por
 * parâmetro da empresa + permissão do perfil, e nenhum valor pro promotor.
 */
class Doc61FaturamentoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private PontoVenda $pdv;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-23 15:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create(['faturamento_contratado' => true]); // contrato liberado pelo SUPERADMIN; o bloqueio sem contrato tem teste próprio
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja Valor']);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ligarFaturamento(bool $ligado = true): void
    {
        Parametro::updateOrCreate(
            ['empresa_id' => $this->empresa->id, 'chave' => 'FATURAMENTO_HABILITADO'],
            ['valor' => $ligado ? 'true' : 'false', 'ativo' => true],
        );
    }

    /** @param array<string, mixed> $extra */
    private function enviarPedido(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pedidos', $extra + [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'numero_pedido' => 'P-'.uniqid(),
            'numero_nf' => 'NF-1',
            'data_pedido' => '2026-09-20',
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 10]],
        ]);
    }

    // ---- regras de valor (unidade) ----

    public function test_so_o_custo_deriva_o_lucro_como_calculado(): void
    {
        $r = ValoresDoPedido::calcular(['itens' => [['quantidade' => 10, 'subtotal' => 200, 'custo_unitario' => 15]]]);

        $this->assertSame(50.0, $r['itens'][0]['valor_lucro']);
        $this->assertSame('CALCULADO', $r['itens'][0]['origem_lucro']);
        $this->assertSame(200.0, $r['pedido']['valor_total']);
        $this->assertSame(50.0, $r['pedido']['valor_lucro_total']);
    }

    public function test_so_o_lucro_deriva_o_custo_e_os_dois_batendo_ficam_como_erp(): void
    {
        $so_lucro = ValoresDoPedido::calcular(['itens' => [['quantidade' => 10, 'subtotal' => 200, 'valor_lucro' => 50]]]);
        $this->assertSame(15.0, $so_lucro['itens'][0]['custo_unitario']);
        $this->assertSame('CALCULADO', $so_lucro['itens'][0]['origem_lucro']);

        $ambos = ValoresDoPedido::calcular(['itens' => [['quantidade' => 10, 'subtotal' => 200, 'custo_unitario' => 15, 'valor_lucro' => 50]]]);
        $this->assertSame('ERP', $ambos['itens'][0]['origem_lucro']);
        $this->assertFalse($ambos['itens'][0]['valor_divergente']);
    }

    public function test_custo_e_lucro_que_nao_batem_sao_gravados_como_vieram_e_marcados(): void
    {
        $r = ValoresDoPedido::calcular(['itens' => [['quantidade' => 10, 'subtotal' => 200, 'custo_unitario' => 15, 'valor_lucro' => 80]]]);

        $this->assertSame(80.0, $r['itens'][0]['valor_lucro']);
        $this->assertTrue($r['itens'][0]['valor_divergente']);
    }

    public function test_total_do_erp_que_diverge_da_soma_dos_itens_e_sinalizado(): void
    {
        $r = ValoresDoPedido::calcular([
            'valor_total' => 999,
            'itens' => [['quantidade' => 1, 'subtotal' => 100], ['quantidade' => 1, 'subtotal' => 100]],
        ]);

        $this->assertSame(999.0, $r['pedido']['valor_total'], 'o ERP é a fonte: grava o dele');
        $this->assertTrue($r['pedido']['valor_divergente']);
    }

    public function test_sem_nenhum_valor_tudo_fica_nulo_e_nunca_zero(): void
    {
        $r = ValoresDoPedido::calcular(['itens' => [['quantidade' => 5]]]);

        $this->assertNull($r['pedido']['valor_total']);
        $this->assertNull($r['pedido']['valor_lucro_total']);
        $this->assertNull($r['itens'][0]['subtotal']);
        $this->assertNull($r['itens'][0]['valor_lucro']);
    }

    public function test_status_derivado_devolucao_negativa_e_faturado_com_nf_e_data(): void
    {
        $dev = ValoresDoPedido::calcular(['valor_total' => -1200, 'itens' => [['quantidade' => 1]]]);
        $fat = ValoresDoPedido::calcular(['valor_total' => 500, 'numero_nf' => 'NF9', 'faturado_em' => '2026-09-21', 'itens' => [['quantidade' => 1]]]);
        $sem = ValoresDoPedido::calcular(['valor_total' => 500, 'itens' => [['quantidade' => 1]]]);
        $erp = ValoresDoPedido::calcular(['valor_total' => -5, 'status_faturamento' => 'CANCELADO', 'itens' => [['quantidade' => 1]]]);

        $this->assertSame('DEVOLVIDO', $dev['pedido']['status_faturamento']);
        $this->assertSame('FATURADO', $fat['pedido']['status_faturamento']);
        $this->assertNull($sem['pedido']['status_faturamento']);
        $this->assertSame('CANCELADO', $erp['pedido']['status_faturamento'], 'o status explícito do ERP vale');
    }

    // ---- gravação e exposição ----

    public function test_valores_sao_gravados_mesmo_com_o_parametro_desligado_mas_nao_expostos(): void
    {
        $r = $this->enviarPedido([
            'valor_total' => 300, 'valor_lucro_total' => 60, 'status_faturamento' => 'FATURADO', 'faturado_em' => '2026-09-21',
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 10, 'preco_unitario' => 30, 'subtotal' => 300, 'valor_lucro' => 60]],
        ])->assertCreated();

        $this->assertDatabaseHas('pedidos', ['numero_nf' => 'NF-1', 'valor_total' => 300, 'status_faturamento' => 'FATURADO']);
        $this->assertDatabaseHas('pedido_itens', ['codigo_externo_produto' => 'A1', 'subtotal' => 300, 'valor_lucro' => 60]);
        // Parâmetro desligado (padrão): nenhuma chave financeira no JSON, nem pro ADMIN.
        $pedido = $r->json('pedido');
        foreach (['valor_total', 'valor_lucro_total', 'margem_pct', 'status_faturamento', 'faturado_em'] as $chave) {
            $this->assertArrayNotHasKey($chave, $pedido);
        }
        $this->assertArrayNotHasKey('subtotal', $pedido['itens'][0]);
    }

    public function test_admin_com_o_parametro_ligado_ve_valor_lucro_e_margem(): void
    {
        $this->ligarFaturamento();

        $r = $this->enviarPedido([
            'valor_total' => 300, 'valor_lucro_total' => 60, 'faturado_em' => '2026-09-21',
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 10, 'subtotal' => 300, 'valor_lucro' => 60]],
        ])->assertCreated();

        $r->assertJsonPath('pedido.valor_total', 300)
            ->assertJsonPath('pedido.valor_lucro_total', 60)
            ->assertJsonPath('pedido.margem_pct', 20)
            ->assertJsonPath('pedido.status_faturamento', 'FATURADO')
            ->assertJsonPath('pedido.itens.0.subtotal', 300);
    }

    public function test_gestor_so_ve_valor_com_a_permissao_do_perfil(): void
    {
        $this->ligarFaturamento();
        $pedido = $this->enviarPedido(['valor_total' => 100])->json('pedido.id');

        $semPerfil = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($semPerfil);
        $this->getJson("/api/pedidos/{$pedido}")->assertOk()->assertJsonMissingPath('pedido.valor_total');

        $perfil = Perfil::factory()->comPermissoes([Permissao::FATURAMENTO_VISUALIZAR->value])->create(['empresa_id' => $this->empresa->id]);
        $comPerfil = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
        Sanctum::actingAs($comPerfil);
        $this->getJson("/api/pedidos/{$pedido}")->assertOk()->assertJsonPath('pedido.valor_total', 100);
    }

    public function test_promotor_nunca_recebe_valor_nem_com_o_parametro_ligado(): void
    {
        $this->ligarFaturamento();
        $criado = $this->enviarPedido([
            'valor_total' => 300, 'valor_lucro_total' => 60, 'status_faturamento' => 'FATURADO', 'faturado_em' => '2026-09-21',
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 10, 'preco_unitario' => 30, 'subtotal' => 300, 'valor_lucro' => 60, 'custo_unitario' => 24]],
        ])->json('pedido.id');

        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);

        $lista = $this->getJson("/api/pontos-venda/{$this->pdv->uuid}/pedidos")->assertOk()->getContent();
        $detalhe = $this->getJson("/api/pedidos/{$criado}")->assertOk()->getContent();

        foreach ([$lista, $detalhe] as $corpo) {
            foreach (['valor_total', 'valor_lucro', 'margem_pct', 'status_faturamento', 'faturado_em', 'preco_unitario', 'subtotal', 'custo_unitario', 'valor_divergente'] as $chave) {
                $this->assertStringNotContainsString("\"{$chave}\"", $corpo, "vazou {$chave} pro promotor");
            }
        }
    }

    public function test_parametro_aparece_na_tela_de_configuracoes_na_area_de_pedidos(): void
    {
        $areas = $this->getJson('/api/parametros/catalogo')->assertOk()->json('areas');
        $item = collect($areas)->flatMap(fn ($a) => $a['itens'])->firstWhere('chave', 'FATURAMENTO_HABILITADO');

        $this->assertNotNull($item);
        $this->assertSame('pedidos', $item['area']);
        $this->assertSame('bool', $item['tipo']);
        $this->assertSame('false', $item['padrao']);
    }

    // ---- Esforço × Retorno ----

    private function visita(PontoVenda $pdv, int $minutos = 60): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->subHours(5), 'fim_data' => now()->subHours(5)->addMinutes($minutos),
        ]);
    }

    private function pedido(PontoVenda $pdv, float $valor, string $status, ?float $lucro = null, string $faturadoEm = '2026-09-21'): void
    {
        Pedido::create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $pdv->id, 'numero_pedido' => 'R-'.uniqid(),
            'data_pedido' => '2026-09-20', 'valor_total' => $valor, 'valor_lucro_total' => $lucro,
            'status_faturamento' => $status, 'faturado_em' => $faturadoEm,
        ]);
    }

    private function relatorio(string $agrupar = 'loja'): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/relatorios/tempo-na-loja?data_inicio=2026-09-15&data_fim=2026-09-23&agrupar={$agrupar}");
    }

    public function test_esforco_x_retorno_traz_faturamento_liquido_lucro_e_por_hora(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv, 60); // 1 h na loja
        $this->pedido($this->pdv, 1000, 'FATURADO', 200);
        $this->pedido($this->pdv, -150, 'DEVOLVIDO', -30);
        $this->pedido($this->pdv, 999, 'CANCELADO');        // fora
        $this->pedido($this->pdv, 888, 'NAO_FATURADO');     // fora

        $linha = $this->relatorio()->assertOk()->json('linhas.0');

        $this->assertEquals(1000.0, $linha['faturamento_bruto']);
        $this->assertEquals(150.0, $linha['devolucoes']);
        $this->assertEquals(850.0, $linha['faturamento_liquido']);
        $this->assertEquals(170.0, $linha['lucro']);
        $this->assertEquals(20.0, $linha['margem_pct']);
        $this->assertEquals(850.0, $linha['faturamento_por_hora']);
        $this->assertSame(2, $linha['pedidos_faturados']);
    }

    public function test_loja_sem_pedido_com_valor_fica_sem_informacao_e_nao_zero(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv);

        $linha = $this->relatorio()->assertOk()->json('linhas.0');

        $this->assertNull($linha['faturamento_liquido']);
        $this->assertNull($linha['faturamento_por_hora']);
        $this->assertNull($linha['margem_pct']);
    }

    public function test_faturado_fora_do_periodo_nao_entra(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv);
        $this->pedido($this->pdv, 500, 'FATURADO', null, '2026-08-01');

        $this->assertNull($this->relatorio()->json('linhas.0.faturamento_liquido'));
    }

    public function test_agrupado_por_rede_e_por_dia_soma_o_faturamento(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv, 30);
        $this->pedido($this->pdv, 400, 'FATURADO', null, '2026-09-23');
        $this->pedido($this->pdv, 600, 'FATURADO', null, '2026-09-22');

        $porDia = collect($this->relatorio('dia')->json('linhas'))->keyBy('chave');
        $this->assertEquals(400.0, $porDia['2026-09-23']['faturamento_liquido'] ?? null);

        $total = $this->relatorio('loja')->json('total');
        $this->assertEquals(1000.0, $total['faturamento_liquido']);
    }

    public function test_por_promotor_nao_atribui_faturamento(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv);
        $this->pedido($this->pdv, 400, 'FATURADO');

        $r = $this->relatorio('promotor')->assertOk();

        $r->assertJsonPath('faturamento.habilitado', true);
        $this->assertNotEmpty($r->json('faturamento.nota'));
        $this->assertArrayNotHasKey('faturamento_liquido', $r->json('linhas.0'));
    }

    public function test_relatorio_sem_o_parametro_ou_sem_permissao_nao_tem_nenhuma_chave_de_valor(): void
    {
        $this->visita($this->pdv);
        $this->pedido($this->pdv, 1000, 'FATURADO', 200);

        // Parâmetro desligado: nem o ADMIN.
        $r = $this->relatorio()->assertOk();
        $this->assertStringNotContainsString('faturamento', $r->getContent());

        // Ligado, mas gestor sem a permissão.
        $this->ligarFaturamento();
        $perfil = Perfil::factory()->comPermissoes([Permissao::TELA_RELATORIOS->value])->create(['empresa_id' => $this->empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor);

        $this->assertStringNotContainsString('faturamento', $this->relatorio()->assertOk()->getContent());
    }

    public function test_faturamento_de_outra_empresa_nunca_entra(): void
    {
        $this->ligarFaturamento();
        $this->visita($this->pdv);
        $outra = Empresa::factory()->create();
        $pdvOutra = PontoVenda::factory()->create(['empresa_id' => $outra->id]);
        Pedido::create([
            'empresa_id' => $outra->id, 'ponto_venda_id' => $pdvOutra->id, 'numero_pedido' => 'X-1',
            'data_pedido' => '2026-09-20', 'valor_total' => 7777, 'status_faturamento' => 'FATURADO', 'faturado_em' => '2026-09-21',
        ]);

        $this->assertStringNotContainsString('7777', $this->relatorio()->assertOk()->getContent());
    }
}
