<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/54-DETALHE-PEDIDO-PREVISAO-E-NOTIFICACAO.md — previsão de chegada vinda do ERP, status
 * A_CAMINHO/ATRASADO/ENTREGUE e o aviso no sino dos promotores da loja.
 */
class Doc54PedidoPrevisaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $loja;

    private Usuario $integrador;

    private Usuario $promotorA;

    private Usuario $promotorB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 15:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create(['fuso' => 'America/Manaus']);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Mercantil']);
        $this->integrador = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $outraLoja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        DB::table('promotor_pontos_venda')->insert([
            ['usuario_id' => $this->promotorA->id, 'ponto_venda_id' => $this->loja->id],
            ['usuario_id' => $this->promotorB->id, 'ponto_venda_id' => $outraLoja->id],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enviar(array $extra = []): string
    {
        Sanctum::actingAs($this->integrador);

        return $this->postJson('/api/pedidos', [
            'ponto_venda_uuid' => $this->loja->uuid,
            'numero_pedido' => 'PED-7',
            'data_pedido' => '2026-09-29',
            'itens' => [['codigo_externo_produto' => 'X1', 'descricao_produto' => 'Biscoito', 'quantidade' => 24]],
            ...$extra,
        ])->assertSuccessful()->json('pedido.id');
    }

    private function avisos(Usuario $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api/pedidos/notificacoes')->assertOk()->json('avisos');
    }

    public function test_status_a_caminho_atrasado_e_entregue(): void
    {
        $id = $this->enviar(['data_previsao_entrega' => '2026-10-03']);
        Sanctum::actingAs($this->promotorA);
        $this->getJson("/api/pedidos/{$id}")->assertOk()
            ->assertJsonPath('pedido.status', 'A_CAMINHO')
            ->assertJsonPath('pedido.data_previsao_entrega', '2026-10-03')
            ->assertJsonPath('pedido.ponto_venda', 'Mercantil');

        // Previsão de ontem, sem entrega = atrasado.
        $this->enviar(['data_previsao_entrega' => '2026-09-30']);
        Sanctum::actingAs($this->promotorA);
        $this->getJson("/api/pedidos/{$id}")->assertJsonPath('pedido.status', 'ATRASADO');

        // Previsão de hoje (no dia de Manaus) ainda não é atraso.
        $this->enviar(['data_previsao_entrega' => '2026-10-01']);
        Sanctum::actingAs($this->promotorA);
        $this->getJson("/api/pedidos/{$id}")->assertJsonPath('pedido.status', 'A_CAMINHO');

        Sanctum::actingAs($this->integrador);
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-10-01 10:00:00'])->assertCreated();
        Sanctum::actingAs($this->promotorA);
        $this->getJson("/api/pedidos/{$id}")->assertJsonPath('pedido.status', 'ENTREGUE');
    }

    public function test_previsao_avisa_so_os_promotores_da_loja_e_de_novo_quando_muda(): void
    {
        $this->enviar(['data_previsao_entrega' => '2026-10-03']);
        $this->assertCount(1, $this->avisos($this->promotorA));
        $this->assertSame('PREVISTO', $this->avisos($this->promotorA)[0]['tipo']);
        $this->assertSame('2026-10-03', $this->avisos($this->promotorA)[0]['data_previsao']);
        $this->assertSame('Mercantil', $this->avisos($this->promotorA)[0]['ponto_venda']);
        $this->assertSame([], $this->avisos($this->promotorB));

        // Reenvio igual (o ERP sincroniza de novo): não avisa outra vez.
        $this->enviar(['data_previsao_entrega' => '2026-10-03']);
        $this->assertCount(1, $this->avisos($this->promotorA));

        // ERP revisou a data: avisa a nova previsão.
        $this->enviar(['data_previsao_entrega' => '2026-10-05']);
        $avisos = $this->avisos($this->promotorA);
        $this->assertCount(2, $avisos);
        $this->assertSame('2026-10-05', $avisos[0]['data_previsao']);
    }

    public function test_pedido_sem_previsao_nao_avisa(): void
    {
        $this->enviar();

        $this->assertSame([], $this->avisos($this->promotorA));
    }

    public function test_entrega_avisa_uma_vez_e_abrir_o_pedido_marca_como_lido(): void
    {
        $id = $this->enviar(['data_previsao_entrega' => '2026-10-03']);
        Sanctum::actingAs($this->integrador);
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-10-02 09:00:00'])->assertCreated();
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-10-02 09:00:00'])->assertOk();

        $avisos = $this->avisos($this->promotorA);
        $this->assertSame(['ENTREGUE', 'PREVISTO'], array_column($avisos, 'tipo'));

        Sanctum::actingAs($this->promotorA);
        $this->postJson("/api/pedidos/{$id}/lido")->assertNoContent();
        $this->getJson('/api/pedidos/notificacoes')->assertJsonPath('total', 0);
    }

    public function test_pedido_de_outra_empresa_nao_abre(): void
    {
        $id = $this->enviar();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => Empresa::factory()->create()->id]));

        $this->getJson("/api/pedidos/{$id}")->assertNotFound();
    }
}
