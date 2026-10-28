<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lojas por trás do relatório Visitas planejadas × executadas: abrir a linha "dia × promotor" e a
 * visão por loja. A lista tem que bater com os números da linha.
 */
class RelatorioLojasPlanejamentoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana Promotora']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loja(string $nome, string $codigo): PontoVenda
    {
        return PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => $nome, 'codigo_externo' => $codigo]);
    }

    private function os(PontoVenda $loja, array $atributos, ?Carbon $dia = null): OrdemServico
    {
        $dia ??= now();

        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $this->promotor->id,
            'prazo_inicio' => $dia->copy()->startOfDay(),
            'prazo_fim' => $dia->copy()->endOfDay()->subHour(),
            ...$atributos,
        ]);
    }

    /** Cenário do dia: 1 visitada, 1 não visitada, 1 a vencer, 1 cancelada e 1 espontânea. */
    private function cenario(): array
    {
        $visitada = $this->loja('Atacadão Torquato', '1042');
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $visitada->id,
            'inicio_data' => now()->startOfDay()->addHours(8), 'fim_data' => now()->startOfDay()->addHours(8)->addMinutes(46),
        ]);
        $os = $this->os($visitada, ['status' => StatusOrdemServico::CONCLUIDA, 'visita_id' => $visita->id]);
        $visita->update(['ordem_servico_id' => $os->id]);

        $naoVisitada = $this->loja('Mercadinho São José', '4410');
        $this->os($naoVisitada, ['status' => StatusOrdemServico::PENDENTE, 'prazo_fim' => now()->subHour()]);

        $aVencer = $this->loja('Assaí Djalma', '1107');
        $this->os($aVencer, ['status' => StatusOrdemServico::PENDENTE]);

        $cancelada = $this->loja('Nova Era', '2210');
        $this->os($cancelada, ['status' => StatusOrdemServico::CANCELADA, 'responsavel_nao_execucao' => 'PROMOTOR', 'motivo_cancelamento_texto' => 'Loja fechada']);

        $espontanea = $this->loja('Rede Litoral', '5120');
        Visita::factory()->finalizada()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $espontanea->id,
            'inicio_data' => now()->startOfDay()->addHours(10),
        ]);

        return compact('visitada', 'naoVisitada', 'aVencer', 'cancelada', 'espontanea');
    }

    public function test_abrir_a_linha_lista_as_lojas_com_a_situacao_e_bate_com_os_numeros(): void
    {
        $this->cenario();
        $hoje = now()->toDateString();

        $linha = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}")->assertOk()->json('linhas.0');
        $lojas = collect($this->getJson("/api/relatorios/visitas-planejadas-x-executadas/lojas?data={$hoje}&usuario_uuid={$this->promotor->uuid}")->assertOk()->json('lojas'));

        $porSituacao = $lojas->countBy('situacao');
        $this->assertSame($linha['cumpridas'], $porSituacao['visitada']);
        $this->assertSame($linha['atrasadas'], $porSituacao['nao_visitada']);
        $this->assertSame($linha['a_vencer'], $porSituacao['a_vencer']);
        $this->assertSame($linha['canceladas'], $porSituacao['cancelada']);
        $this->assertSame($linha['espontaneas'], $porSituacao['espontanea']);

        $visitada = $lojas->firstWhere('situacao', 'visitada');
        $this->assertSame(['Atacadão Torquato', '1042'], [$visitada['loja']['nome'], $visitada['loja']['codigo_externo']]);
        $this->assertSame('08:00', $visitada['entrada']);
        $this->assertSame(46, $visitada['minutos_na_loja']);
        $this->assertSame('Loja fechada', $lojas->firstWhere('situacao', 'cancelada')['motivo_cancelamento']);
        $this->assertSame('PROMOTOR', $lojas->firstWhere('situacao', 'cancelada')['responsavel_cancelamento']);
    }

    public function test_linha_de_outro_promotor_e_de_outro_dia_nao_aparecem(): void
    {
        $this->cenario();
        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->os($this->loja('Do outro', '9001'), ['usuario_id' => $outro->id, 'status' => StatusOrdemServico::PENDENTE]);
        $this->os($this->loja('Ontem', '9002'), ['status' => StatusOrdemServico::PENDENTE], now()->subDay());

        $codigos = collect($this->getJson('/api/relatorios/visitas-planejadas-x-executadas/lojas?data='.now()->toDateString()."&usuario_uuid={$this->promotor->uuid}")->json('lojas'))
            ->pluck('loja.codigo_externo');

        $this->assertNotContains('9001', $codigos);
        $this->assertNotContains('9002', $codigos);
        $this->assertCount(5, $codigos);
    }

    public function test_os_sem_promotor_aparece_na_linha_sem_promotor(): void
    {
        $this->os($this->loja('Fila aberta', '7000'), ['usuario_id' => null, 'status' => StatusOrdemServico::PENDENTE]);

        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas/lojas?data='.now()->toDateString().'&sem_promotor=1')
            ->assertOk()
            ->assertJsonCount(1, 'lojas')
            ->assertJsonPath('lojas.0.promotor', null);
    }

    public function test_por_loja_agrupa_o_periodo_e_mostra_os_dias_perdidos(): void
    {
        $loja = $this->loja('Mercadinho São José', '4410');
        $this->os($loja, ['status' => StatusOrdemServico::PENDENTE], now()->subDays(3));
        $this->os($loja, ['status' => StatusOrdemServico::PENDENTE], now()->subDays(1));
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $loja->id,
            'inicio_data' => now()->subDays(5)->setTime(9, 0),
        ]);
        $this->os($loja, ['status' => StatusOrdemServico::CONCLUIDA, 'visita_id' => $visita->id], now()->subDays(5));
        $emDia = $this->loja('Em dia', '1000');
        $v2 = Visita::factory()->finalizada()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $emDia->id, 'inicio_data' => now()->setTime(8, 0)]);
        $this->os($emDia, ['status' => StatusOrdemServico::CONCLUIDA, 'visita_id' => $v2->id]);

        $inicio = now()->subDays(6)->toDateString();
        $fim = now()->toDateString();
        $resposta = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas/por-loja?data_inicio={$inicio}&data_fim={$fim}")
            ->assertOk()
            ->assertJsonPath('total_lojas', 2)
            ->assertJsonPath('lojas_com_perda', 1)
            ->assertJsonPath('lojas.0.loja.codigo_externo', '4410')
            ->assertJsonPath('lojas.0.planejadas', 3)
            ->assertJsonPath('lojas.0.visitadas', 1)
            ->assertJsonPath('lojas.0.nao_visitadas', 2)
            ->assertJsonPath('lojas.0.dias_sem_visita', 5);
        $this->assertSame([now()->subDays(3)->toDateString(), now()->subDays(1)->toDateString()], $resposta->json('lojas.0.dias_perdidos'));

        $this->getJson("/api/relatorios/visitas-planejadas-x-executadas/por-loja?data_inicio={$inicio}&data_fim={$fim}&so_perdidas=1")
            ->assertJsonCount(1, 'lojas');
    }

    public function test_rota_do_dia_traz_o_planejado_que_nao_virou_visita(): void
    {
        $this->cenario();

        $planejadas = collect($this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=".now()->toDateString())
            ->assertOk()
            ->json('planejadas_nao_feitas'));

        $this->assertEqualsCanonicalizing(['nao_visitada', 'a_vencer', 'cancelada'], $planejadas->pluck('situacao')->all());
        $this->assertNotNull($planejadas->firstWhere('situacao', 'nao_visitada')['loja']['latitude']);
    }

    public function test_promotor_nao_acessa_e_outra_empresa_nao_aparece(): void
    {
        $outra = Empresa::factory()->create();
        OrdemServico::factory()->create([
            'empresa_id' => $outra->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $outra->id])->id,
            'prazo_inicio' => now()->startOfDay(), 'prazo_fim' => now()->endOfDay()->subHour(), 'status' => StatusOrdemServico::PENDENTE,
        ]);

        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas/por-loja')->assertOk()->assertJsonPath('total_lojas', 0);

        Sanctum::actingAs($this->promotor);
        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas/lojas?data='.now()->toDateString())->assertForbidden();
        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas/por-loja')->assertForbidden();
    }
}
