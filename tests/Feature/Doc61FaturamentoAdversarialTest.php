<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Pedido;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Entradas hostis e combinações estranhas nas mudanças novas: valor/faturamento no pedido (docs/61),
 * Esforço × Retorno, exportação e filtros. O que importa aqui é nunca dar 500, nunca vazar valor
 * e nunca misturar empresas.
 */
class Doc61FaturamentoAdversarialTest extends TestCase
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
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ligar(): void
    {
        Parametro::updateOrCreate(
            ['empresa_id' => $this->empresa->id, 'chave' => 'FATURAMENTO_HABILITADO'],
            ['valor' => 'true', 'ativo' => true],
        );
    }

    /** @param array<string, mixed> $extra */
    private function pedido(array $extra = [], ?string $numero = null)
    {
        return $this->postJson('/api/pedidos', $extra + [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'numero_pedido' => $numero ?? 'P-'.uniqid(),
            'data_pedido' => '2026-09-20',
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 1]],
        ]);
    }

    // ——— Entradas hostis em POST /pedidos ———

    public function test_valores_fora_do_que_a_coluna_aguenta_sao_recusados_e_nao_viram_500(): void
    {
        // decimal(14,2): 99999999999999 passa de 12 dígitos inteiros.
        $this->pedido(['valor_total' => 99999999999999])->assertStatus(422)->assertJsonValidationErrors('valor_total');
        $this->pedido(['valor_lucro_total' => 1e20])->assertStatus(422)->assertJsonValidationErrors('valor_lucro_total');
        $this->pedido([
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 1, 'subtotal' => 99999999999999]],
        ])->assertStatus(422);
        $this->pedido([
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 1, 'preco_unitario' => 1e15]],
        ])->assertStatus(422);
    }

    public function test_tipos_errados_nos_campos_novos_sao_422(): void
    {
        $this->pedido(['valor_total' => 'abc'])->assertStatus(422);
        $this->pedido(['valor_total' => ['1']])->assertStatus(422);
        $this->pedido(['status_faturamento' => 'XPTO'])->assertStatus(422);
        $this->pedido(['faturado_em' => 'nao-e-data'])->assertStatus(422);
        $this->pedido(['faturado_em' => '2026-02-31'])->assertStatus(422);
        $this->pedido([
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => 1, 'subtotal' => 'caro']],
        ])->assertStatus(422);
    }

    public function test_quantidade_zero_ou_negativa_e_itens_vazios_continuam_recusados(): void
    {
        $this->pedido(['itens' => []])->assertStatus(422);
        $this->pedido([
            'itens' => [['codigo_externo_produto' => 'A1', 'descricao_produto' => 'Arroz', 'quantidade' => -3]],
        ])->assertStatus(422);
    }

    public function test_reenvio_do_mesmo_pedido_com_outros_valores_nao_duplica_nem_quebra(): void
    {
        $this->pedido(['valor_total' => 100], 'P-REENVIO')->assertCreated();
        $segunda = $this->pedido(['valor_total' => 250], 'P-REENVIO');

        $this->assertLessThan(500, $segunda->status());
        $this->assertSame(1, Pedido::withoutGlobalScopes()->where('numero_pedido', 'P-REENVIO')->count());
    }

    public function test_pedido_de_outra_empresa_nao_aparece_nem_com_o_faturamento_ligado(): void
    {
        $this->ligar();
        $outra = Empresa::factory()->create();
        $pdvAlheio = PontoVenda::factory()->create(['empresa_id' => $outra->id]);
        $alheio = Pedido::withoutGlobalScopes()->create([
            'empresa_id' => $outra->id, 'ponto_venda_id' => $pdvAlheio->id, 'numero_pedido' => 'ALHEIO',
            'data_pedido' => '2026-09-20', 'valor_total' => 7777,
        ]);

        $this->getJson("/api/pedidos/{$alheio->uuid}")->assertNotFound();
        $this->getJson("/api/pontos-venda/{$pdvAlheio->uuid}/pedidos")->assertNotFound();
    }

    // ——— Quem pode chamar o relatório ———

    public function test_promotor_nao_acessa_o_relatorio_de_tempo_na_loja(): void
    {
        $this->ligar();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->getJson('/api/relatorios/tempo-na-loja?data_inicio=2026-09-15&data_fim=2026-09-23')->assertForbidden();
    }

    public function test_filtros_invalidos_do_relatorio_nunca_geram_500(): void
    {
        $this->ligar();

        foreach ([
            '?data_inicio=2026-09-23&data_fim=2026-09-15',
            '?data_inicio=lixo&data_fim=2026-09-23',
            '?data_inicio=2026-09-15&data_fim=2026-09-23&agrupar=inexistente',
            '?data_inicio=2026-09-15&data_fim=2026-09-23&ponto_venda_uuid=nao-existe',
            '?data_inicio=2026-09-15&data_fim=2026-09-23&usuario_uuid[]=1',
            '?data_inicio=1900-01-01&data_fim=2100-01-01',
        ] as $consulta) {
            $this->assertLessThan(500, $this->getJson('/api/relatorios/tempo-na-loja'.$consulta)->status(), $consulta);
        }
    }

    public function test_relatorio_com_milhares_de_pedidos_e_visitas_responde_sem_estourar(): void
    {
        $this->ligar();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $lojas = PontoVenda::factory()->count(30)->create(['empresa_id' => $this->empresa->id]);

        foreach ($lojas as $loja) {
            Visita::factory()->create([
                'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $loja->id,
                'status' => StatusVisita::FINALIZADA,
                'inicio_data' => now()->subHours(6), 'fim_data' => now()->subHours(5),
            ]);
            foreach (range(1, 20) as $i) {
                Pedido::create([
                    'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $loja->id, 'numero_pedido' => "L{$loja->id}-{$i}",
                    'data_pedido' => '2026-09-20', 'valor_total' => $i % 5 === 0 ? -50 : 100 + $i,
                    'status_faturamento' => $i % 5 === 0 ? 'DEVOLVIDO' : 'FATURADO', 'faturado_em' => '2026-09-21',
                ]);
            }
        }

        $r = $this->getJson('/api/relatorios/tempo-na-loja?data_inicio=2026-09-15&data_fim=2026-09-23')->assertOk();
        $this->assertCount(30, $r->json('linhas'));
        $this->assertSame(600, $r->json('total.pedidos_faturados'));
        // Conferência de soma: por loja, 16 faturados (100+i, i≠múltiplo de 5) e 4 devoluções de 50.
        $faturadoPorLoja = collect(range(1, 20))->reject(fn ($i) => $i % 5 === 0)->sum(fn ($i) => 100 + $i);
        $this->assertEquals(30 * ($faturadoPorLoja - 200), $r->json('total.faturamento_liquido'));
    }

    // ——— Exportação não pode vazar nem quebrar ———

    public function test_exportacao_de_tempo_na_loja_com_faturamento_ligado_nao_quebra_nem_vaza_auxiliares(): void
    {
        $this->ligar();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $this->pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->subHours(6), 'fim_data' => now()->subHours(5),
        ]);
        Pedido::create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'numero_pedido' => 'X1',
            'data_pedido' => '2026-09-20', 'valor_total' => 500, 'status_faturamento' => 'FATURADO', 'faturado_em' => '2026-09-21',
        ]);

        foreach (['csv', 'xlsx'] as $formato) {
            $r = $this->postJson('/api/exportacoes', [
                'rotina' => 'tempo-na-loja', 'formato' => $formato,
                'filtros' => ['data_inicio' => '2026-09-15', 'data_fim' => '2026-09-23', 'agrupar' => 'loja'],
            ]);

            $r->assertOk();
            $conteudo = file_get_contents($r->baseResponse->getFile()->getPathname());
            $this->assertNotSame('', $conteudo);
            // CSV é texto; o XLSX vai compactado, então a checagem textual só vale pro CSV.
            if ($formato === 'csv') {
                $this->assertStringNotContainsString('lojas_por_grupo', $conteudo);
            }
        }
    }

    // ——— Perfil e permissão ———

    public function test_gestor_com_permissao_mas_parametro_desligado_nao_ve_nada(): void
    {
        $perfil = Perfil::factory()->comPermissoes([Permissao::FATURAMENTO_VISUALIZAR->value, Permissao::TELA_RELATORIOS->value])
            ->create(['empresa_id' => $this->empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor);

        $r = $this->getJson('/api/relatorios/tempo-na-loja?data_inicio=2026-09-15&data_fim=2026-09-23')->assertOk();

        $this->assertStringNotContainsString('faturamento', $r->getContent());
    }

    public function test_desligar_o_parametro_depois_de_ligar_esconde_de_novo(): void
    {
        $this->ligar();
        $id = $this->pedido(['valor_total' => 100])->assertCreated()->json('pedido.id');
        $this->getJson("/api/pedidos/{$id}")->assertJsonPath('pedido.valor_total', 100);

        Parametro::where('empresa_id', $this->empresa->id)->where('chave', 'FATURAMENTO_HABILITADO')->update(['valor' => 'false']);

        $this->getJson("/api/pedidos/{$id}")->assertOk()->assertJsonMissingPath('pedido.valor_total');
    }

    // ——— Tipos de registro: busca com caracteres especiais ———

    public function test_busca_de_tipos_de_registro_com_curingas_e_aspas_nao_quebra(): void
    {
        foreach (['%', '_', '\\', "'", '"; DROP TABLE tipos_registro;--', '%%__\\\\', str_repeat('a', 5000)] as $busca) {
            $r = $this->getJson('/api/tipos-registro?busca='.rawurlencode($busca));
            $this->assertLessThan(500, $r->status(), 'busca: '.substr($busca, 0, 30));
        }
        $this->getJson('/api/tipos-registro?disponivel_registro_livre=talvez')->assertOk();
    }
}
