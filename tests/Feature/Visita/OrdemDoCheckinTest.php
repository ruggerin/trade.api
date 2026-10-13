<?php

namespace Tests\Feature\Visita;

use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Qual OS a visita cumpre quando a loja tem mais de uma pendente — App\Support\OrdemDoCheckin.
 * Caso real de produção (13/10/2026): o app mandava a OS vencida de domingo e a de hoje ficava
 * pendente, porque escolhia pelo prazo que vence antes e a API não olhava a data.
 */
class OrdemDoCheckinTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $pdv;

    protected function setUp(): void
    {
        parent::setUp();
        // Meio da tarde em Brasília: longe da virada do dia em qualquer fuso usado aqui.
        $this->travelTo(Carbon::parse('2026-10-13 15:00', 'America/Sao_Paulo'));
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->promotor);
    }

    /** OS de um dia local inteiro (fuso da loja), N dias a partir de hoje (negativo = passado). */
    private function os(int $dias, array $atributos = []): OrdemServico
    {
        $dia = now('America/Sao_Paulo')->addDays($dias);

        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $this->pdv->id,
            'usuario_id' => $this->promotor->id,
            'prazo_inicio' => $dia->copy()->startOfDay(),
            'prazo_fim' => $dia->copy()->endOfDay(),
            ...$atributos,
        ]);
    }

    private function checkin(OrdemServico $enviada, ?string $inicioEm = null, bool $escolhida = false): string
    {
        return $this->postJson('/api/visitas', array_filter([
            'ponto_venda_uuid' => $this->pdv->uuid,
            'latitude' => $this->pdv->latitude,
            'longitude' => $this->pdv->longitude,
            'ordem_servico_uuid' => $enviada->uuid,
            'ordem_servico_escolhida' => $escolhida ?: null,
            'inicio_em' => $inicioEm,
        ]))->assertSuccessful()->json('visita.ordem_servico.id') ?? '';
    }

    public function test_os_escolhida_pelo_promotor_vale_como_veio_mesmo_vencida(): void
    {
        // Ele tocou no card da OS de domingo na Agenda pra resolvê-la: é ela que fica em andamento.
        $domingo = $this->os(-2);
        $hoje = $this->os(0);

        $this->assertSame($domingo->uuid, $this->checkin($domingo, escolhida: true));
        $this->assertSame(StatusOrdemServico::EM_ANDAMENTO, $domingo->fresh()->status);
        $this->assertSame(StatusOrdemServico::PENDENTE, $hoje->fresh()->status);
    }

    public function test_duas_idas_na_mesma_loja_cada_uma_cumpre_a_os_do_seu_card(): void
    {
        $manha = $this->os(0, ['horario_previsto' => '09:00']);
        $tarde = $this->os(0, ['horario_previsto' => '15:00']);

        $this->assertSame($tarde->uuid, $this->checkin($tarde, escolhida: true));
        $this->assertSame(StatusOrdemServico::PENDENTE, $manha->fresh()->status);
    }

    public function test_app_manda_a_vencida_de_domingo_e_a_visita_cumpre_a_de_hoje(): void
    {
        $domingo = $this->os(-2);
        $hoje = $this->os(0);

        $this->assertSame($hoje->uuid, $this->checkin($domingo));
        $this->assertSame(StatusOrdemServico::EM_ANDAMENTO, $hoje->fresh()->status);
        $this->assertSame(StatusOrdemServico::PENDENTE, $domingo->fresh()->status);
    }

    public function test_sem_os_do_dia_a_vencida_enviada_vale_como_visita_atrasada(): void
    {
        $ontem = $this->os(-1);

        $this->assertSame($ontem->uuid, $this->checkin($ontem));
        $this->assertSame(StatusOrdemServico::EM_ANDAMENTO, $ontem->fresh()->status);
    }

    public function test_os_do_dia_enviada_pelo_app_e_mantida(): void
    {
        $this->os(-2);
        $hoje = $this->os(0);

        $this->assertSame($hoje->uuid, $this->checkin($hoje));
    }

    public function test_dentro_do_dia_a_api_nao_desfaz_a_escolha_do_app(): void
    {
        // Duas do dia: a escolha entre elas (formulário pendente primeiro) é do app.
        $semFormulario = $this->os(0);
        $comFormulario = $this->os(0, ['prazo_fim' => now('America/Sao_Paulo')->addDays(3)->endOfDay()]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja Perfeita']);
        $comFormulario->formularios()->attach($tipo->id, ['obrigatorio' => true]);

        $this->assertSame($semFormulario->uuid, $this->checkin($semFormulario));
    }

    public function test_vencida_enviada_troca_pela_do_dia_com_formulario_pendente_primeiro(): void
    {
        $vencida = $this->os(-3);
        $this->os(0);
        $direcionamento = $this->os(-1, ['prazo_fim' => now('America/Sao_Paulo')->addDays(5)->endOfDay()]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Pesquisa de preço']);
        $direcionamento->formularios()->attach($tipo->id, ['obrigatorio' => true]);

        $this->assertSame($direcionamento->uuid, $this->checkin($vencida));
    }

    public function test_os_da_fila_aberta_do_dia_ganha_da_vencida_do_proprio_promotor(): void
    {
        $vencida = $this->os(-2);
        $filaAberta = $this->os(0, ['usuario_id' => null]);

        $this->assertSame($filaAberta->uuid, $this->checkin($vencida));
    }

    public function test_os_de_outro_promotor_nunca_e_escolhida(): void
    {
        $vencida = $this->os(-2);
        $this->os(0, ['usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id])->id]);

        $this->assertSame($vencida->uuid, $this->checkin($vencida));
    }

    public function test_checkin_offline_vale_pro_dia_em_que_aconteceu(): void
    {
        // Check-in feito ontem sem sinal e enviado hoje: a OS de ontem era a do dia da visita.
        $ontem = $this->os(-1);
        $this->os(0);
        $ontemAsTres = now('America/Sao_Paulo')->subDay()->setTime(15, 0)->toIso8601String();

        $this->assertSame($ontem->uuid, $this->checkin($ontem, $ontemAsTres));
    }

    public function test_dia_da_visita_segue_o_fuso_da_loja(): void
    {
        // 22h em Boa Vista (UTC-4) já é dia seguinte em UTC, mas a visita é "hoje" na loja.
        $this->empresa->update(['fuso' => 'America/Boa_Vista']);
        $this->travelTo(Carbon::parse('2026-10-13 22:00', 'America/Boa_Vista'));
        $hoje = OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
            'prazo_inicio' => Carbon::parse('2026-10-13 00:00', 'America/Boa_Vista'),
            'prazo_fim' => Carbon::parse('2026-10-13 23:59:59', 'America/Boa_Vista'),
        ]);
        $vencida = OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
            'prazo_inicio' => Carbon::parse('2026-10-11 00:00', 'America/Boa_Vista'),
            'prazo_fim' => Carbon::parse('2026-10-11 23:59:59', 'America/Boa_Vista'),
        ]);

        $this->assertSame($hoje->uuid, $this->checkin($vencida));
    }
}
