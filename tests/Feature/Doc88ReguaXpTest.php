<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Models\ClassePontoVenda;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\XpLancamento;
use App\Models\XpResumoDiario;
use App\Support\Xp\ComposicaoXp;
use App\Support\Xp\ParametrosXp;
use App\Support\Xp\ReguaXp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/88 — régua de performance com XP: cálculo da visita, perdas e resumo diário.
 */
class Doc88ReguaXpTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Usuario $promotor;

    protected PontoVenda $loja;

    protected ClassePontoVenda $chefao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        $this->empresa = Empresa::factory()->create(['controle_xp_habilitado' => true, 'fuso' => 'America/Sao_Paulo']);
        $this->chefao = ClassePontoVenda::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'nome' => 'Chefão', 'xp_base' => 500, 'duracao_padrao_minutos' => 150, 'eh_padrao' => true,
        ]);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function os(array $extra = []): OrdemServico
    {
        return OrdemServico::factory()->create($extra + [
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $this->loja->id,
            'usuario_id' => $this->promotor->id,
            'prazo_inicio' => Carbon::parse('2026-10-20 00:00', 'America/Sao_Paulo'),
            'prazo_fim' => Carbon::parse('2026-10-20 23:59', 'America/Sao_Paulo'),
            'horario_previsto' => '10:00',
        ]);
    }

    /** Check-in na loja e check-out pela API, como o app faz. */
    protected function visitar(OrdemServico $os, string $saida = '12:00', array $checkout = ['obrigatorias_feitas' => 3, 'obrigatorias_total' => 3], ?callable $durante = null): Visita
    {
        Sanctum::actingAs($this->promotor);
        $id = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $this->loja->uuid,
            'latitude' => $this->loja->latitude,
            'longitude' => $this->loja->longitude,
            'ordem_servico_uuid' => $os->uuid,
            'ordem_servico_escolhida' => true,
            'inicio_em' => '2026-10-20T10:00:00-03:00',
        ])->assertSuccessful()->json('visita.id');
        if ($durante) {
            $durante($id);
        }

        Carbon::setTestNow(Carbon::parse("2026-10-20 {$saida}", 'America/Sao_Paulo'));
        $this->patchJson("/api/visitas/{$id}/checkout", [
            'latitude' => $this->loja->latitude,
            'longitude' => $this->loja->longitude,
            'fim_em' => Carbon::parse("2026-10-20 {$saida}", 'America/Sao_Paulo')->toIso8601String(),
        ] + $checkout)->assertOk();

        return Visita::where('uuid', $id)->firstOrFail();
    }

    protected function somaOficial(Visita $visita, string $natureza): int
    {
        return (int) XpLancamento::where('visita_id', $visita->id)->where('status', 'OFICIAL')->where('natureza', $natureza)->sum('pontos');
    }

    public function test_os_congela_classe_xp_e_duracao_quando_nasce(): void
    {
        $os = $this->os();
        $this->assertSame(500, $os->xp_previsto);
        $this->assertSame(150, $os->duracao_prevista_minutos);

        // Mudar a classe depois não reescreve a OS já gerada.
        $this->chefao->update(['xp_base' => 900]);
        $this->assertSame(500, $os->fresh()->xp_previsto);
    }

    public function test_pesos_da_empresa_congelam_na_os_e_mudar_depois_nao_reescreve(): void
    {
        // Presença 5, completude 50 (o resto igual): a OS nasce com essa composição.
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->putJson('/api/parametros/lote', ['alteracoes' => [
            ['chave' => 'XP_PESO_PRESENCA', 'valor' => '5'],
            ['chave' => 'XP_PESO_COMPLETUDE', 'valor' => '50'],
        ]])->assertOk();
        ParametrosXp::limparCache();
        $os = $this->os();
        $this->assertSame(5, $os->xp_composicao['presenca']);
        $this->assertSame(50, $os->xp_composicao['completude']);

        // Pesos mudam de novo depois: a OS já criada mantém os dela.
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->putJson('/api/parametros/lote', ['alteracoes' => [
            ['chave' => 'XP_PESO_PRESENCA', 'valor' => '15'],
            ['chave' => 'XP_PESO_COMPLETUDE', 'valor' => '40'],
        ]])->assertOk();
        ParametrosXp::limparCache();

        // Metade das obrigatórias: perde metade de 50% de 500 = 125 (no padrão seriam 100).
        $visita = $this->visitar($os, '12:00', ['obrigatorias_feitas' => 1, 'obrigatorias_total' => 2]);
        $this->assertSame(125, (int) XpLancamento::where('visita_id', $visita->id)->where('categoria', 'obrigatorias_incompletas')->sum('pontos'));
        $this->assertSame(500, $this->somaOficial($visita, 'PLANO') + $this->somaOficial($visita, 'PERDA'));
    }

    public function test_pesos_precisam_somar_100(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'XP_PESO_ENVIO', 'valor' => '10']]])
            ->assertStatus(422)
            ->assertJsonFragment(['Os pesos do XP da visita precisam somar 100% (hoje somam 105%).']);

        // Soma torta gravada por fora (tabela avançada): a OS usa a composição padrão.
        \App\Models\Parametro::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'chave' => 'XP_PESO_ENVIO', 'valor' => '30', 'descricao' => 'x', 'ativo' => true,
        ]);
        ParametrosXp::limparCache();
        $this->assertSame(ComposicaoXp::PARTES, $this->os()->xp_composicao);
    }

    public function test_visita_perfeita_ganha_todo_o_xp_da_classe(): void
    {
        $visita = $this->visitar($this->os());

        $this->assertSame(500, $this->somaOficial($visita, 'PLANO'));
        $this->assertSame(0, $this->somaOficial($visita, 'PERDA'));

        $resumo = XpResumoDiario::where('usuario_id', $this->promotor->id)->whereDate('dia', '2026-10-20')->firstOrFail();
        $this->assertSame(500, $resumo->possivel);
        $this->assertSame(500, $resumo->realizado);
        $this->assertSame(1, $resumo->visitas_completas);
    }

    public function test_incompleta_perde_proporcional_e_zera_o_tempo(): void
    {
        $visita = $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 1, 'obrigatorias_total' => 2]);

        $categorias = XpLancamento::where('visita_id', $visita->id)->where('natureza', 'PERDA')->pluck('pontos', 'categoria')->all();
        // 40% de 500 = 200, metade feita.
        $this->assertSame(100, $categorias['obrigatorias_incompletas']);
        // Anti-correria: pontualidade (50) e tempo (50) zeram.
        $this->assertSame(50, $categorias['atraso_inicio']);
        $this->assertSame(50, $categorias['estouro_duracao']);
        // Qualidade sem formulário com nota: proporcional à completude.
        $this->assertSame(50, $categorias['qualidade']);
        $this->assertSame(500, $this->somaOficial($visita, 'PLANO') + $this->somaOficial($visita, 'PERDA'));
    }

    public function test_estouro_de_duracao_perde_a_metade_do_tempo(): void
    {
        // 150 min previstos + 20% = 180; ficou 200.
        $visita = $this->visitar($this->os(), '13:20');

        $this->assertSame(450, $this->somaOficial($visita, 'PLANO'));
        $this->assertSame(50, (int) XpLancamento::where('visita_id', $visita->id)->where('categoria', 'estouro_duracao')->sum('pontos'));
    }

    public function test_visita_curta_demais_nao_pontua(): void
    {
        // Mínimo de 30% de 150 = 45 min; ficou 20.
        $visita = $this->visitar($this->os(), '10:20');

        $this->assertSame(0, $this->somaOficial($visita, 'PLANO'));
        $this->assertSame(500, (int) XpLancamento::where('visita_id', $visita->id)->where('categoria', 'visita_curta')->sum('pontos'));
    }

    public function test_sem_os_nao_ha_xp_e_empresa_sem_o_recurso_nao_calcula(): void
    {
        $this->empresa->update(['controle_xp_habilitado' => false]);
        $this->visitar($this->os());

        $this->assertSame(0, XpLancamento::count());
    }

    public function test_os_nao_realizada_vira_perda_no_fechamento_do_dia_e_some_quando_feita(): void
    {
        $os = $this->os();
        Carbon::setTestNow(Carbon::parse('2026-10-21 00:30', 'America/Sao_Paulo'));
        ReguaXp::fecharDia($this->empresa, '2026-10-20');

        $perda = XpLancamento::where('ordem_servico_id', $os->id)->whereNull('visita_id')->sum('pontos');
        $this->assertSame(500, (int) $perda);
        $this->assertSame(['visita_nao_realizada' => 500], XpResumoDiario::firstOrFail()->perdas);

        // Rodar de novo não duplica.
        ReguaXp::fecharDia($this->empresa, '2026-10-20');
        $this->assertSame(500, (int) XpLancamento::where('ordem_servico_id', $os->id)->whereNull('visita_id')->sum('pontos'));
    }

    public function test_fechar_dia_sem_nenhuma_os_nao_quebra(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 00:30', 'America/Sao_Paulo'));
        $this->assertSame(0, ReguaXp::fecharDia($this->empresa, '2026-10-25'));
    }

    public function test_os_cancelada_antes_do_congelamento_sai_do_possivel(): void
    {
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'cancelada_em' => Carbon::parse('2026-10-20 05:00', 'America/Sao_Paulo')]);
        $depois = $this->os(['status' => StatusOrdemServico::CANCELADA, 'cancelada_em' => Carbon::parse('2026-10-20 09:00', 'America/Sao_Paulo')]);

        Carbon::setTestNow(Carbon::parse('2026-10-21 00:30', 'America/Sao_Paulo'));
        ReguaXp::fecharDia($this->empresa, '2026-10-20');

        $resumo = XpResumoDiario::firstOrFail();
        $this->assertSame(500, $resumo->possivel);
        $this->assertSame(500, (int) XpLancamento::where('ordem_servico_id', $depois->id)->sum('pontos'));
    }

    public function test_cancelamento_pelo_gestor_estorna_com_motivo(): void
    {
        $visita = $this->visitar($this->os());
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Check-in em loja errada'])->assertOk();

        $estorno = XpLancamento::where('visita_id', $visita->id)->where('natureza', 'ESTORNO');
        $this->assertSame(-500, (int) $estorno->sum('pontos'));
        $this->assertStringContainsString('loja errada', $estorno->first()->motivo);
        // Histórico preservado: os lançamentos do plano continuam lá.
        $this->assertSame(500, (int) XpLancamento::where('visita_id', $visita->id)->where('natureza', 'PLANO')->sum('pontos'));
        $this->assertSame(0, XpResumoDiario::firstOrFail()->realizado);
    }
}
