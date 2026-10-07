<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Painéis padrão (docs/59): visitas canceladas contadas, comparativo de períodos e tempo dentro
 * do PDV.
 */
class Doc59RelatoriosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function os(array $atributos): OrdemServico
    {
        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $this->empresa->id])->id,
            'usuario_id' => $this->promotor->id,
            'prazo_inicio' => now()->startOfDay(),
            'prazo_fim' => now()->endOfDay()->subHour(),
            ...$atributos,
        ]);
    }

    public function test_canceladas_sao_contadas_por_responsavel_e_motivo(): void
    {
        $motivo = MotivoNaoExecucao::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Promotor faltou']);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'responsavel_nao_execucao' => 'PROMOTOR', 'motivo_cancelamento_id' => $motivo->id]);
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'responsavel_nao_execucao' => 'LOJA', 'motivo_cancelamento_texto' => 'Loja fechada']);
        $hoje = now()->toDateString();

        $total = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}")
            ->assertOk()->json('total');

        $this->assertSame(1, $total['planejadas']);
        $this->assertSame(2, $total['canceladas']);
        $this->assertSame(1, $total['canceladas_promotor']);
        $this->assertSame(['PROMOTOR' => 1, 'LOJA' => 1], $total['canceladas_por_responsavel']);
        $this->assertSame(100, $total['percentual_cumprimento']);
        // Faltar e justificar não melhora o número do promotor: 1 cumprida ÷ (1 planejada + 1 cancelada por ele).
        $this->assertSame(50, $total['percentual_cumprimento_ajustado']);
        $motivos = collect($total['canceladas_por_motivo'])->pluck('quantidade', 'motivo');
        $this->assertSame(1, $motivos['Promotor faltou']);
        $this->assertSame(1, $motivos['Outro (texto livre)']);
    }

    public function test_comparativo_traz_o_periodo_anterior_equivalente(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        // 23/09 é hoje; o período anterior de 1 dia é 22/09.
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_inicio' => now()->subDay()->startOfDay(), 'prazo_fim' => now()->subDay()->endOfDay()->subHour()]);
        $this->os(['status' => StatusOrdemServico::PENDENTE, 'prazo_inicio' => now()->subDay()->startOfDay(), 'prazo_fim' => now()->subDay()->endOfDay()->subHour()]);
        $hoje = now()->toDateString();

        $resposta = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}&comparar=anterior")
            ->assertOk();

        $resposta->assertJsonPath('total.planejadas', 1)
            ->assertJsonPath('comparativo.tipo', 'anterior')
            ->assertJsonPath('comparativo.periodo.data_inicio', '2026-09-22')
            ->assertJsonPath('comparativo.periodo.data_fim', '2026-09-22')
            ->assertJsonPath('comparativo.total.planejadas', 2)
            ->assertJsonPath('comparativo.total.cumpridas', 1);
    }

    public function test_comparativo_ano_anterior_usa_as_mesmas_datas(): void
    {
        $hoje = now()->toDateString();

        $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}&comparar=ano_anterior")
            ->assertOk()
            ->assertJsonPath('comparativo.periodo.data_inicio', '2025-09-23');
    }

    public function test_comparar_invalido_e_recusado(): void
    {
        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas?comparar=semana')->assertInvalid(['comparar']);
    }

    public function test_tempo_na_loja_desconta_afastamento_e_ignora_visitas_fora_da_faixa(): void
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja A']);
        $nova = fn (int $inicioMin, int $duracaoMin, array $extra = []) => Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->startOfDay()->addMinutes($inicioMin),
            'fim_data' => now()->startOfDay()->addMinutes($inicioMin + $duracaoMin),
        ] + $extra);

        $nova(480, 40);                                  // 40 min
        $nova(540, 30, ['afastamento_minutos' => 10]);   // 30 - 10 = 20 min
        $nova(600, 1);                                   // < 2 min: desconsiderada
        $nova(620, 800);                                 // > 12 h: desconsiderada
        Visita::factory()->create([                      // sem checkout: fora
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id,
            'inicio_data' => now()->startOfDay()->addHours(7),
        ]);
        $hoje = now()->toDateString();

        $resposta = $this->getJson("/api/relatorios/tempo-na-loja?data_inicio={$hoje}&data_fim={$hoje}")->assertOk();

        $resposta->assertJsonPath('total.visitas', 2)
            ->assertJsonPath('total.tempo_total_minutos', 60)
            ->assertJsonPath('total.media_minutos', 30)
            ->assertJsonPath('total.mediana_minutos', 30)
            ->assertJsonPath('total.desconsideradas', 2)
            ->assertJsonPath('linhas.0.nome', 'Loja A');
    }

    public function test_tempo_na_loja_agrupa_por_promotor_e_compara_periodos(): void
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $nova = fn ($dia, int $duracao) => Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => $dia->copy()->startOfDay()->addHours(8),
            'fim_data' => $dia->copy()->startOfDay()->addHours(8)->addMinutes($duracao),
        ]);
        $nova(now(), 30);
        $nova(now()->subDay(), 60);
        $hoje = now()->toDateString();

        $resposta = $this->getJson("/api/relatorios/tempo-na-loja?data_inicio={$hoje}&data_fim={$hoje}&agrupar=promotor&comparar=anterior")
            ->assertOk();

        $resposta->assertJsonPath('linhas.0.nome', 'Ana')
            ->assertJsonPath('linhas.0.media_minutos', 30)
            ->assertJsonPath('linhas.0.media_minutos_comparativo', 60)
            ->assertJsonPath('comparativo.total.media_minutos', 60);
    }

    public function test_tempo_na_loja_traz_itens_trabalhados_e_minutos_por_item(): void
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->startOfDay()->addHours(8),
            'fim_data' => now()->startOfDay()->addHours(8)->addMinutes(30),
        ]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura']);
        [$a, $b] = ProdutoAuditoria::factory()->count(2)->create(['empresa_id' => $this->empresa->id]);
        // 2 produtos distintos (o repetido e o cancelado não contam).
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'produto_auditoria_id' => $a->id]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'produto_auditoria_id' => $a->id]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'produto_auditoria_id' => $b->id]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'produto_auditoria_id' => ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id])->id, 'cancelado_em' => now()]);
        $hoje = now()->toDateString();

        $this->getJson("/api/relatorios/tempo-na-loja?data_inicio={$hoje}&data_fim={$hoje}")
            ->assertOk()
            ->assertJsonPath('total.itens_trabalhados', 2)
            ->assertJsonPath('total.minutos_por_item', 15)
            ->assertJsonPath('linhas.0.itens_trabalhados', 2);
    }

    public function test_tempo_na_loja_sem_registro_por_produto_fica_sem_informacao_e_nao_zero(): void
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->startOfDay()->addHours(8),
            'fim_data' => now()->startOfDay()->addHours(8)->addMinutes(30),
        ]);
        $hoje = now()->toDateString();

        $this->getJson("/api/relatorios/tempo-na-loja?data_inicio={$hoje}&data_fim={$hoje}")
            ->assertOk()
            ->assertJsonPath('total.itens_trabalhados', null)
            ->assertJsonPath('total.minutos_por_item', null);
    }

    public function test_tempo_na_loja_so_para_admin_ou_gestor(): void
    {
        Sanctum::actingAs($this->promotor);

        $this->getJson('/api/relatorios/tempo-na-loja')->assertForbidden();
    }
}
