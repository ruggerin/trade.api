<?php

namespace Tests\Feature;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\OperacaoDoDia;
use App\Support\Rastreamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/95 — intervalo (almoço) desconsiderado: sem cobrança de rastreamento, sem "sem sinal" na
 * Operação do Dia e escondido na Rota do dia. Empresa em UTC pra hora do teste ser a hora local.
 */
class Doc95IntervaloDesconsideradoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 12:30:00');
        config(['services.mapbox.token' => '']);
        Http::fake();

        $this->empresa = Empresa::factory()->create(['fuso' => 'UTC']);
        $this->parametro('RASTREAMENTO_INTERVALO_SEGUNDOS', '60');
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Jonatas']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parametro(string $chave, string $valor): void
    {
        Parametro::updateOrCreate(['empresa_id' => $this->empresa->id, 'chave' => $chave], ['valor' => $valor, 'ativo' => true]);
    }

    private function ligarIntervalo(): void
    {
        $this->parametro('JORNADA_INTERVALO_HABILITADO', 'true');
    }

    private function admin(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    public function test_desligado_por_padrao_nada_muda(): void
    {
        $this->assertNull(OperacaoDoDia::jornada($this->empresa)['intervalo']);
        $this->assertTrue(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 12:30')));
    }

    public function test_ligado_usa_12_00_13_30_e_tira_da_jornada(): void
    {
        $this->ligarIntervalo();

        $this->assertSame(['inicio' => '12:00', 'fim' => '13:30'], OperacaoDoDia::jornada($this->empresa)['intervalo']);
        $this->assertTrue(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 11:59')));
        $this->assertFalse(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 12:00')));
        $this->assertFalse(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 13:30')));
        $this->assertTrue(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 13:31')));

        // Mesmo com "o dia inteiro conta como jornada", o intervalo não conta.
        $this->parametro('RASTREAMENTO_SO_NA_JORNADA', 'false');
        $this->assertFalse(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 12:45')));
    }

    public function test_horario_proprio_e_cadastro_invertido_vira_sem_intervalo(): void
    {
        $this->ligarIntervalo();
        $this->parametro('JORNADA_INTERVALO_INICIO', '11:00');
        $this->parametro('JORNADA_INTERVALO_FIM', '12:00');
        $this->assertTrue(OperacaoDoDia::emIntervalo($this->empresa, Carbon::parse('2026-10-22 11:30')));
        $this->assertFalse(OperacaoDoDia::emIntervalo($this->empresa, Carbon::parse('2026-10-22 12:30')));

        $this->parametro('JORNADA_INTERVALO_FIM', '10:00');
        $this->assertNull(OperacaoDoDia::jornada($this->empresa)['intervalo']);
    }

    public function test_jornada_e_intervalo_valem_na_hora_local_da_empresa(): void
    {
        // Manaus = UTC-4: 15:30 UTC é 11:30 local (jornada), 16:30 UTC é 12:30 local (intervalo).
        $this->empresa->update(['fuso' => 'America/Manaus']);
        $this->ligarIntervalo();

        $this->assertTrue(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 15:30', 'UTC')));
        $this->assertFalse(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 16:30', 'UTC')));
        // 19:30 UTC = 15:30 local: dentro da jornada (antes da correção, 19:30 contava como fora).
        $this->assertTrue(Rastreamento::dentroDaJornada($this->empresa, Carbon::parse('2026-10-22 19:30', 'UTC')));
    }

    public function test_conformidade_nao_cobra_no_intervalo_nem_logo_depois(): void
    {
        $this->ligarIntervalo();
        $this->parametro('RASTREAMENTO_PAINEL_CONFORMIDADE', 'true');
        $this->promotor->forceFill(['rastreamento_situacao' => 'ATIVO', 'ultima_localizacao_em' => Carbon::parse('2026-10-22 11:59')])->save();
        $this->admin();

        $this->getJson('/api/localizacoes/conformidade')->assertOk()
            ->assertJsonPath('dentro_da_jornada', false)
            ->assertJsonPath('no_intervalo', true)
            ->assertJsonPath('jornada.intervalo.inicio', '12:00')
            ->assertJsonCount(0, 'irregulares');

        // 13:33: voltou há 3 min (tolerância 5) — ainda não é sem sinal.
        Carbon::setTestNow('2026-10-22 13:33:00');
        $this->getJson('/api/localizacoes/conformidade')->assertOk()->assertJsonCount(0, 'irregulares');

        // 13:40: passou da folga e a última posição continua de 11:59.
        Carbon::setTestNow('2026-10-22 13:40:00');
        $this->getJson('/api/localizacoes/conformidade')->assertOk()
            ->assertJsonPath('no_intervalo', false)
            ->assertJsonPath('irregulares.0.motivo', 'SEM_SINAL');
    }

    public function test_operacao_do_dia_sem_sinal_e_fila_ficam_quietos_no_intervalo(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $this->promotor->id,
            'prazo_fim' => now(),
        ]);
        $this->promotor->forceFill(['ultima_localizacao_em' => Carbon::parse('2026-10-22 11:50')])->save();
        $this->admin();

        // Sem o parâmetro: sem sinal, como antes.
        $this->getJson('/api/operacao-do-dia')->assertOk()->assertJsonPath('equipe.0.sem_sinal', true);

        $this->ligarIntervalo();
        $resposta = $this->getJson('/api/operacao-do-dia')->assertOk()
            ->assertJsonPath('equipe.0.sem_sinal', false)
            ->assertJsonPath('jornada.intervalo.fim', '13:30');
        $this->assertNotContains('SINAL', collect($resposta->json('fila_acoes'))->pluck('tipo')->all());
    }

    private function ponto(string $hora, float $lat, float $lng): void
    {
        DB::table('localizacoes_historico')->insert([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'capturado_em' => Carbon::parse("2026-10-21 {$hora}"),
        ]);
    }

    /** Um ponto por minuto de $de a $ate, de [lat, lng] a [lat, lng]. */
    private function trajeto(string $de, string $ate, array $origem, array $destino): void
    {
        $inicio = Carbon::parse("2026-10-21 {$de}");
        $minutos = (int) $inicio->diffInMinutes(Carbon::parse("2026-10-21 {$ate}"));
        for ($m = 0; $m <= $minutos; $m++) {
            $f = $minutos ? $m / $minutos : 0;
            $this->ponto($inicio->copy()->addMinutes($m)->format('H:i'), $origem[0] + ($destino[0] - $origem[0]) * $f, $origem[1] + ($destino[1] - $origem[1]) * $f);
        }
    }

    /**
     * 06:50–07:30 andando (começa antes do expediente) · 08:00–08:30 visita · 11:00–11:59 andando ·
     * 12:00–13:00 almoço parado longe de tudo · 13:01–13:30 anda pro norte · 13:31–14:00 volta.
     */
    private function montarDia(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'latitude' => -3.1, 'longitude' => -60.0]);
        $this->trajeto('06:50', '07:30', [-3.13, -59.99], [-3.10, -60.00]);
        $this->trajeto('08:00', '08:30', [-3.10, -60.00], [-3.10, -60.00]);
        $this->trajeto('11:00', '11:59', [-3.10, -60.00], [-3.12, -60.01]);
        $this->trajeto('12:00', '13:00', [-3.03, -60.00], [-3.03, -60.00]);
        $this->trajeto('13:01', '13:30', [-3.03, -60.00], [-3.00, -60.00]);
        $this->trajeto('13:31', '14:00', [-3.00, -60.00], [-3.10, -60.00]);

        Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse('2026-10-21 08:00'),
            'fim_data' => Carbon::parse('2026-10-21 08:30'),
        ]);
    }

    private function rota()
    {
        $this->admin();

        return $this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-10-21")->assertOk();
    }

    public function test_rota_do_dia_esconde_o_intervalo_mesmo_no_dia_inteiro(): void
    {
        $this->ligarIntervalo();
        $this->parametro('RASTREAMENTO_DESVIO_RAIO_METROS', '3000');
        $this->montarDia();

        $rota = $this->rota()
            ->assertJsonPath('expediente.recorte', 'DIA_INTEIRO')
            ->assertJsonPath('expediente.intervalo.inicio', '2026-10-21T12:00:00+00:00')
            // O almoço parado não entra no resumo, mas fica no bloco do intervalo (12:00–13:01: no
            // primeiro minuto da saída ele ainda está a menos de 100 m do lugar).
            ->assertJsonPath('resumo.parado_fora_minutos', 0)
            ->assertJsonPath('fora_do_expediente.intervalo.parado_fora_minutos', 61);

        $this->assertNull($rota->json('fora_do_expediente.antes'));
        $this->assertContains(true, $rota->json('linhas_fora_expediente'));
        // Longe da loja no almoço não é desvio; só o que passou das 13:30 conta.
        foreach ($rota->json('desvios') as $d) {
            $this->assertTrue(Carbon::parse($d['inicio'])->gt(Carbon::parse('2026-10-21 13:30')));
        }
        // Os pontos continuam vindo (o gestor pode abrir o bloco).
        $this->assertContains('2026-10-21T12:30:00+00:00', array_column($rota->json('pontos'), 'em'));
    }

    public function test_so_expediente_nem_manda_os_pontos_do_intervalo(): void
    {
        $this->ligarIntervalo();
        $this->parametro('RASTREAMENTO_ROTA_RECORTE', 'SO_EXPEDIENTE');
        $this->montarDia();

        $rota = $this->rota()->assertJsonPath('fora_do_expediente', null);

        $horas = array_map(fn ($em) => Carbon::parse($em)->format('H:i'), array_column($rota->json('pontos'), 'em'));
        $this->assertNotContains('12:30', $horas);
        $this->assertContains('11:59', $horas);
        $this->assertContains('13:31', $horas);
    }

    public function test_trecho_que_cruza_o_inicio_do_expediente_fica_do_lado_certo(): void
    {
        // 06:50→07:30 contínuo: o pedaço depois das 07:00 é do expediente e tem de aparecer.
        $this->parametro('RASTREAMENTO_ROTA_RECORTE', 'EXPEDIENTE');
        $this->montarDia();

        $flags = $this->rota()->json('linhas_fora_expediente');

        $this->assertTrue($flags[0], 'antes das 07:00 fica escondido');
        $this->assertFalse($flags[1], 'depois das 07:00 aparece');
    }
}
