<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Afastamento durante a visita — docs/49-AFASTAMENTO-DURANTE-VISITA.md. Loja em (-3.1, -60.0);
 * "longe" = -3.127 (~3 km). Padrões: 300 m / 10 min; intervalo 60 s → tolerância sem sinal 5 min.
 */
class AfastamentoVisitaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $loja;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 18:00:00');
        config(['services.mapbox.token' => '']);

        $this->empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60', 'ativo' => true]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'latitude' => -3.1, 'longitude' => -60.0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pontos a cada 5 min de $de até $ate (inclusive) na mesma posição. */
    private function pontos(string $de, string $ate, float $lat, float $lng = -60.0): void
    {
        for ($t = Carbon::parse("2026-09-29 {$de}"); $t->lte(Carbon::parse("2026-09-29 {$ate}")); $t->addMinutes(5)) {
            DB::table('localizacoes_historico')->insert([
                'empresa_id' => $this->empresa->id,
                'usuario_id' => $this->promotor->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'capturado_em' => $t->copy(),
            ]);
        }
    }

    private function visita(string $inicio = '08:00', string $fim = '10:00'): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse("2026-09-29 {$inicio}"),
            'fim_data' => Carbon::parse("2026-09-29 {$fim}"),
        ]);
    }

    /** Na loja 08:00–08:30, a ~3 km 08:35–09:15, de volta 09:20–10:00. */
    private function saiuEVoltou(): Visita
    {
        $this->pontos('08:00', '08:30', -3.1);
        $this->pontos('08:35', '09:15', -3.127);
        $this->pontos('09:20', '10:00', -3.1);

        return $this->visita();
    }

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
    }

    public function test_rota_do_dia_mostra_a_saida_da_loja_e_desconta_do_tempo_em_loja(): void
    {
        $this->saiuEVoltou();
        Sanctum::actingAs($this->admin());

        $rota = $this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-09-29")->assertOk();

        $rota->assertJsonCount(1, 'visitas.0.afastamentos')
            ->assertJsonPath('visitas.0.afastamentos.0.minutos', 45)
            ->assertJsonPath('visitas.0.minutos_fora', 45)
            ->assertJsonPath('resumo.fora_da_loja_minutos', 45)
            ->assertJsonPath('resumo.tempo_em_loja_minutos', 75)
            ->assertJsonPath('parametros.afastamento_metros', 300);
        $this->assertStringStartsWith('2026-09-29T08:35', $rota->json('visitas.0.afastamentos.0.inicio'));
        $this->assertStringStartsWith('2026-09-29T09:20', $rota->json('visitas.0.afastamentos.0.fim'));
        $this->assertGreaterThan(2900, $rota->json('visitas.0.afastamentos.0.distancia_max_metros'));
        // Trecho pro mapa: último ponto perto + os longe + o de volta.
        $this->assertCount(11, $rota->json('visitas.0.afastamentos.0.pontos'));
    }

    public function test_salto_de_gps_de_um_ponto_so_nao_conta(): void
    {
        $this->pontos('08:00', '08:30', -3.1);
        $this->pontos('08:35', '08:35', -3.127);
        $this->pontos('08:40', '10:00', -3.1);
        $visita = $this->visita();

        $this->assertSame([], \App\Support\AfastamentoVisita::calcular($visita)['afastamentos']);
    }

    public function test_sem_sinal_no_meio_da_visita_nao_vira_afastamento(): void
    {
        $this->pontos('08:00', '08:30', -3.1);
        $this->pontos('09:30', '10:00', -3.1);
        $visita = $this->visita();

        $resultado = \App\Support\AfastamentoVisita::calcular($visita);

        $this->assertSame([], $resultado['afastamentos']);
        $this->assertSame(60, $resultado['sem_sinal_minutos']);
    }

    public function test_saiu_e_fez_checkout_longe_conta_ate_o_checkout(): void
    {
        $this->pontos('08:00', '09:00', -3.1);
        $this->pontos('09:05', '10:00', -3.127);
        $visita = $this->visita();

        $afastamento = \App\Support\AfastamentoVisita::calcular($visita)['afastamentos'][0];

        $this->assertNull($afastamento['fim']);
        $this->assertSame(55, $afastamento['minutos']);
    }

    public function test_comando_grava_so_visita_finalizada_ha_mais_de_30_min(): void
    {
        $antiga = $this->saiuEVoltou();
        $semPosicao = $this->visita('11:00', '12:00');
        $recente = $this->visita('17:00', '17:45');

        $this->artisan('visitas:calcular-afastamento')->assertSuccessful();

        $antiga->refresh();
        $this->assertSame(1, $antiga->afastamento_qtd);
        $this->assertSame(45, $antiga->afastamento_minutos);
        $this->assertNotNull($antiga->afastamento_calculado_em);

        // Sem posição na janela: marca como calculada, mas sem afirmar nada.
        $semPosicao->refresh();
        $this->assertNull($semPosicao->afastamento_qtd);
        $this->assertNotNull($semPosicao->afastamento_calculado_em);

        $this->assertNull($recente->refresh()->afastamento_calculado_em);
    }

    public function test_lista_filtra_detalhe_mostra_e_feed_destaca(): void
    {
        $comSaida = $this->saiuEVoltou();
        $this->pontos('11:00', '12:00', -3.1);
        $semSaida = $this->visita('11:00', '12:00');
        $this->artisan('visitas:calcular-afastamento');
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/visitas?afastamento=1')->assertOk()
            ->assertJsonCount(1, 'visitas')
            ->assertJsonPath('visitas.0.id', $comSaida->uuid)
            ->assertJsonPath('visitas.0.afastamento.minutos', 45);

        $this->getJson("/api/visitas/{$comSaida->uuid}")->assertOk()
            ->assertJsonCount(1, 'afastamento.afastamentos')
            ->assertJsonPath('afastamento.metros', 300);
        $this->getJson("/api/visitas/{$semSaida->uuid}")->assertOk()
            ->assertJsonCount(0, 'afastamento.afastamentos');

        $eventos = collect($this->getJson('/api/atividades?data_inicio=2026-09-29&data_fim=2026-09-29')->assertOk()->json('eventos'));
        $this->assertSame(45, $eventos->firstWhere('id', "saida:{$comSaida->uuid}")['afastamento']['minutos']);
        $this->assertNull($eventos->firstWhere('id', "saida:{$semSaida->uuid}")['afastamento']);
    }

    public function test_gestor_sem_permissao_da_rota_nao_ve_afastamento(): void
    {
        $visita = $this->saiuEVoltou();
        $this->artisan('visitas:calcular-afastamento');
        $perfil = Perfil::factory()->comPermissoes([Permissao::RASTREAMENTO_VISUALIZAR->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]));

        $this->getJson("/api/visitas/{$visita->uuid}")->assertOk()
            ->assertJsonMissingPath('visita.afastamento')
            ->assertJsonPath('afastamento', null);
        // Filtro ignorado: não dá pra usar a lista pra descobrir quem saiu.
        $this->getJson('/api/visitas?afastamento=1')->assertOk()->assertJsonCount(1, 'visitas');
    }
}
