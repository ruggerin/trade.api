<?php

namespace Tests\Feature;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/93 — Rota do dia: recorte no expediente, deslocamentos com bairro e trechos fora da área do
 * roteiro. Empresa em UTC pra hora do teste ser a hora da tela; expediente padrão 07:00–17:00.
 */
class Doc93DeslocamentoDesvioRotaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $loja;

    private PontoVenda $lojaAgenda;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 23:00:00');
        config(['services.mapbox.token' => 'pk.teste']);

        $this->empresa = Empresa::factory()->create(['fuso' => 'UTC']);
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60', 'ativo' => true]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Jonatas']);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'DB Loja 19', 'latitude' => -3.100000, 'longitude' => -60.000000]);
        // Da agenda, não visitada: ~2,2 km a leste da loja visitada.
        $this->lojaAgenda = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Atacadão Flores', 'latitude' => -3.100000, 'longitude' => -59.980000]);

        Http::fake([
            'api.mapbox.com/matching/*' => Http::response(['code' => 'Ok', 'matchings' => [['geometry' => ['coordinates' => [[-60.0, -3.1], [-60.05, -3.15]]]]]]),
            // Norte da cidade (lat > -3.07) = Lagoa Azul; o resto = Chapada.
            'api.mapbox.com/geocoding/*' => fn (Request $r) => Http::response(['features' => [[
                'place_type' => ['neighborhood'],
                'text' => (float) explode(',', basename(parse_url($r->url(), PHP_URL_PATH), '.json'))[1] > -3.07 ? 'Lagoa Azul' : 'Chapada',
            ]]]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parametro(string $chave, string $valor): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => $chave, 'valor' => $valor, 'ativo' => true]);
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

    /** Um ponto por minuto de $de a $ate, indo em linha reta de [lat, lng] a [lat, lng]. */
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
     * 05:00–06:00 parado em casa (antes do expediente) · 08:00–08:30 visita na loja · 08:31–09:00
     * vai pro norte (~8 km) · 09:01–09:40 rodando lá longe · 09:41–10:10 volta · 10:11–10:50 parado
     * fora de loja · 18:00–19:00 parado em casa (depois do expediente).
     */
    private function montarDia(): void
    {
        $casa = [-3.130, -59.990];
        $norte = [-3.030, -60.000];
        $meio = [-3.120, -60.010];

        $this->trajeto('05:00', '06:00', $casa, $casa);
        $this->trajeto('07:40', '07:59', $casa, [-3.1, -60.0]);
        $this->trajeto('08:00', '08:30', [-3.1, -60.0], [-3.1, -60.0]);
        $this->trajeto('08:31', '09:00', [-3.1, -60.0], $norte);
        $this->trajeto('09:01', '09:40', $norte, [-3.040, -60.010]);
        $this->trajeto('09:41', '10:10', [-3.040, -60.010], $meio);
        $this->trajeto('10:11', '10:50', $meio, $meio);
        $this->trajeto('18:00', '19:00', $casa, $casa);

        Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse('2026-10-21 08:00'),
            'fim_data' => Carbon::parse('2026-10-21 08:30'),
        ]);
        OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->lojaAgenda->id,
            'prazo_inicio' => Carbon::parse('2026-10-21 00:00'),
            'prazo_fim' => Carbon::parse('2026-10-21 18:00'),
        ]);
    }

    private function rota()
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        return $this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-10-21")->assertOk();
    }

    public function test_sem_parametro_continua_o_dia_inteiro_e_sem_desvio(): void
    {
        $this->montarDia();

        $rota = $this->rota()
            ->assertJsonPath('expediente.recorte', 'DIA_INTEIRO')
            ->assertJsonPath('desvios', [])
            ->assertJsonPath('area_roteiro.lojas', [])
            ->assertJsonPath('fora_do_expediente', null);

        $this->assertNotContains(true, $rota->json('linhas_fora_expediente'));
        // Parada de casa às 05:00 (60) + parada do meio (40) + 18:00 (60): o dia inteiro conta.
        $this->assertSame(160, $rota->json('resumo.parado_fora_minutos'));
    }

    public function test_recorte_expediente_tira_a_noite_do_resumo_e_resume_o_lado_de_fora(): void
    {
        $this->parametro('RASTREAMENTO_ROTA_RECORTE', 'EXPEDIENTE');
        $this->montarDia();

        $rota = $this->rota()
            ->assertJsonPath('expediente.recorte', 'EXPEDIENTE')
            ->assertJsonPath('expediente.inicio', '2026-10-21T07:00:00+00:00')
            ->assertJsonPath('resumo.parado_fora_minutos', 40)
            ->assertJsonPath('fora_do_expediente.antes.parado_fora_minutos', 60)
            ->assertJsonPath('fora_do_expediente.depois.parado_fora_minutos', 60);

        // O gestor pode abrir: os pontos de fora continuam vindo, e as linhas dizem de que lado estão.
        $this->assertSame('2026-10-21T05:00:00+00:00', $rota->json('pontos.0.em'));
        $this->assertContains(true, $rota->json('linhas_fora_expediente'));
        $this->assertContains(false, $rota->json('linhas_fora_expediente'));
        $this->assertCount(count($rota->json('linhas')), $rota->json('linhas_fora_expediente'));
    }

    public function test_so_expediente_nem_manda_o_que_e_de_fora(): void
    {
        $this->parametro('RASTREAMENTO_ROTA_RECORTE', 'SO_EXPEDIENTE');
        $this->montarDia();

        $rota = $this->rota()->assertJsonPath('fora_do_expediente', null);

        $this->assertSame('2026-10-21T07:40:00+00:00', $rota->json('pontos.0.em'));
        $this->assertSame('2026-10-21T10:50:00+00:00', $rota->json('resumo.ultima_posicao_em'));
        $this->assertCount(1, $rota->json('paradas'));
    }

    public function test_fora_da_area_do_roteiro_com_horario_distancia_e_bairro(): void
    {
        $this->parametro('RASTREAMENTO_DESVIO_RAIO_METROS', '3000');
        $this->montarDia();

        $rota = $this->rota();

        // Loja visitada e loja da agenda não visitada formam a área.
        $this->assertEqualsCanonicalizing(['DB Loja 19', 'Atacadão Flores'], array_column($rota->json('area_roteiro.lojas'), 'fantasia'));
        $this->assertSame([true, false], array_column($rota->json('area_roteiro.lojas'), 'visitada'));

        $desvios = $rota->json('desvios');
        $this->assertCount(1, $desvios);
        // Sai do raio de 3 km por volta das 08:41 e volta por volta das 10:0x.
        $this->assertGreaterThanOrEqual(Carbon::parse('2026-10-21 08:38'), Carbon::parse($desvios[0]['inicio']));
        $this->assertLessThanOrEqual(Carbon::parse('2026-10-21 10:10'), Carbon::parse($desvios[0]['fim']));
        $this->assertGreaterThan(60, $desvios[0]['minutos']);
        $this->assertGreaterThan(7000, $desvios[0]['distancia_max_metros']);
        $this->assertContains('Lagoa Azul', $desvios[0]['bairros']);
        $this->assertNotEmpty($desvios[0]['pontos']);
        $this->assertSame($desvios[0]['minutos'], $rota->json('resumo.fora_da_area_minutos'));
    }

    public function test_desvio_curto_nao_conta(): void
    {
        $this->parametro('RASTREAMENTO_DESVIO_RAIO_METROS', '3000');
        $this->parametro('RASTREAMENTO_DESVIO_MINUTOS', '240');
        $this->montarDia();

        $this->rota()->assertJsonPath('desvios', [])->assertJsonPath('resumo.fora_da_area_minutos', 0);
    }

    public function test_deslocamento_entre_a_visita_e_a_parada_com_bairros(): void
    {
        $this->montarDia();

        $deslocamentos = $this->rota()->json('deslocamentos');

        // 07:40→08:00 (até a loja) e 08:30→10:10 (do checkout até a parada fora de loja). A volta pra
        // casa à noite não tem ponto em movimento, e ficar parado em casa não é deslocamento.
        $daLoja = collect($deslocamentos)->first(fn ($d) => Carbon::parse($d['inicio'])->eq(Carbon::parse('2026-10-21 08:30')));
        $this->assertNotNull($daLoja, 'deslocamento que começa no checkout');
        $this->assertTrue(Carbon::parse($daLoja['fim'])->eq(Carbon::parse('2026-10-21 10:10')));
        $this->assertGreaterThan(15000, $daLoja['distancia_metros']);
        $this->assertSame(['Chapada', 'Lagoa Azul'], $daLoja['bairros']);
    }

    public function test_saida_durante_a_visita_nao_vira_deslocamento(): void
    {
        $this->trajeto('08:00', '08:05', [-3.1, -60.0], [-3.1, -60.0]);
        $this->trajeto('08:06', '08:20', [-3.1, -60.0], [-3.1, -60.05]);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse('2026-10-21 08:00'),
            'fim_data' => Carbon::parse('2026-10-21 08:20'),
        ]);

        $this->rota()->assertJsonPath('deslocamentos', []);
    }

    public function test_bairro_fica_em_cache_e_nao_consulta_de_novo(): void
    {
        $this->montarDia();

        $this->rota();
        $consultas = collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'geocoding'))->count();
        $this->rota();

        $this->assertGreaterThan(0, $consultas);
        $this->assertSame($consultas, collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'geocoding'))->count());
    }
}
