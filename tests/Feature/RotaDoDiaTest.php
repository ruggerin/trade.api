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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/48-ROTA-DO-DIA.md — histórico de posições, montagem da rota (visitas, paradas fora de loja,
 * sem sinal), linha pelas ruas via Mapbox com cache, limpeza e permissão própria.
 */
class RotaDoDiaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $loja;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 18:00:00');
        config(['services.mapbox.token' => 'pk.teste']);

        $this->empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60', 'ativo' => true]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Juliana']);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Mercantil', 'latitude' => -3.100000, 'longitude' => -60.000000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ponto(string $hora, float $lat, float $lng): void
    {
        DB::table('localizacoes_historico')->insert([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'capturado_em' => Carbon::parse("2026-09-29 {$hora}"),
        ]);
    }

    /** 08:00–08:30 na loja (visita), 09:00–09:40 parada longe de qualquer loja ("casa"), sem sinal 10:00→11:00. */
    private function montarDia(): void
    {
        foreach (['08:00', '08:05', '08:10', '08:15', '08:20', '08:25', '08:30'] as $h) {
            $this->ponto($h, -3.100000, -60.000000);
        }
        // Deslocamento (um ponto a cada 5 min, andando).
        foreach (['08:35' => [-3.110, -60.010], '08:40' => [-3.120, -60.020], '08:45' => [-3.130, -60.030], '08:50' => [-3.140, -60.040], '08:55' => [-3.145, -60.045]] as $h => [$lat, $lng]) {
            $this->ponto($h, $lat, $lng);
        }
        foreach (['09:00', '09:05', '09:10', '09:15', '09:20', '09:25', '09:30', '09:35', '09:40'] as $h) {
            $this->ponto($h, -3.150000, -60.050000);
        }
        foreach (['09:45' => [-3.155, -60.055], '09:50' => [-3.153, -60.053], '09:55' => [-3.152, -60.052], '10:00' => [-3.151, -60.051]] as $h => [$lat, $lng]) {
            $this->ponto($h, $lat, $lng);
        }
        $this->ponto('11:00', -3.120000, -60.020000);

        Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse('2026-09-29 08:03'),
            'fim_data' => Carbon::parse('2026-09-29 08:31'),
        ]);
    }

    private function fakeMapbox(): void
    {
        Http::fake(['api.mapbox.com/*' => Http::response([
            'code' => 'Ok',
            'matchings' => [['geometry' => ['coordinates' => [[-60.0, -3.1], [-60.02, -3.12], [-60.05, -3.15]]]]],
        ])]);
    }

    public function test_promotor_enviando_posicao_grava_no_historico_sem_duplicar(): void
    {
        Sanctum::actingAs($this->promotor);
        $payload = ['latitude' => -3.1, 'longitude' => -60.0, 'capturado_em' => '2026-09-29T12:00:00-04:00'];

        $this->patchJson('/api/localizacao', $payload)->assertNoContent();
        $this->patchJson('/api/localizacao', $payload)->assertNoContent();

        $this->assertSame(1, DB::table('localizacoes_historico')->where('usuario_id', $this->promotor->id)->count());
    }

    public function test_rota_traz_visitas_parada_fora_de_loja_e_sem_sinal(): void
    {
        $this->montarDia();
        $this->fakeMapbox();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $rota = $this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-09-29")->assertOk();

        $rota->assertJsonPath('promotor.nome', 'Juliana')
            ->assertJsonPath('visitas.0.ordem', 1)
            ->assertJsonPath('visitas.0.ponto_venda.fantasia', 'Mercantil')
            ->assertJsonPath('visitas.0.minutos', 28)
            ->assertJsonPath('aproximada', false)
            ->assertJsonPath('resumo.visitas', 1);

        // Os 30 min parado na loja são a visita — só a parada longe (40 min) conta.
        $this->assertCount(1, $rota->json('paradas'));
        $this->assertSame(40, $rota->json('paradas.0.minutos'));
        // Tolerância = máx(5, 3×60s) = 5 min: 10:00 → 11:00 é buraco sem sinal.
        $this->assertCount(1, $rota->json('sem_sinal'));
        $this->assertSame(60, $rota->json('sem_sinal.0.minutos'));
        $this->assertCount(2, $rota->json('linhas'));
    }

    public function test_dia_fecha_a_meia_noite_do_fuso_de_quem_consulta(): void
    {
        // 22:00 de Manaus (UTC-4) = 02:00 UTC do dia seguinte — o histórico é UTC.
        DB::table('localizacoes_historico')->insert([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'latitude' => -3.1,
            'longitude' => -60.0,
            'capturado_em' => Carbon::parse('2026-09-30 02:00:00', 'UTC'),
        ]);
        config(['services.mapbox.token' => '']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $url = "/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-09-29";
        $this->assertCount(1, $this->getJson($url.'&tz=America/Manaus')->assertOk()->json('pontos'));
        $this->assertCount(0, $this->getJson($url)->assertOk()->json('pontos'));
        $this->getJson($url.'&tz=Nao/Existe')->assertUnprocessable();
    }

    public function test_linha_pelas_ruas_fica_em_cache_e_nao_chama_o_mapbox_de_novo(): void
    {
        $this->montarDia();
        $this->fakeMapbox();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $url = "/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-09-29";

        $this->getJson($url)->assertOk();
        $chamadas = count(Http::recorded());
        $this->getJson($url)->assertOk();

        $this->assertGreaterThan(0, $chamadas);
        $this->assertSame($chamadas, count(Http::recorded()));
    }

    public function test_sem_token_ou_com_falha_a_linha_e_reta_e_aproximada(): void
    {
        $this->montarDia();
        config(['services.mapbox.token' => '']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->getJson("/api/rotas?usuario_uuid={$this->promotor->uuid}&data=2026-09-29")->assertOk()
            ->assertJsonPath('aproximada', true);
        $this->assertSame(0, DB::table('rotas_dia')->count());
    }

    public function test_permissao_propria_separada_do_mapa_ao_vivo(): void
    {
        $perfilMapa = Perfil::factory()->comPermissoes([Permissao::RASTREAMENTO_VISUALIZAR->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfilMapa->id]));
        $this->getJson('/api/rotas/promotores')->assertForbidden();

        $perfilRota = Perfil::factory()->comPermissoes([Permissao::RASTREAMENTO_TRAJETO->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfilRota->id]));
        $this->getJson('/api/rotas/promotores')->assertOk()->assertJsonPath('promotores.0.nome', 'Juliana');
    }

    public function test_promotor_de_outra_empresa_da_404(): void
    {
        $outra = Usuario::factory()->promotor()->create(['empresa_id' => Empresa::factory()->create()->id]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->getJson("/api/rotas?usuario_uuid={$outra->uuid}&data=2026-09-29")->assertNotFound();
    }

    public function test_limpeza_apaga_so_o_que_passou_do_parametro(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'RASTREAMENTO_HISTORICO_DIAS', 'valor' => '10', 'ativo' => true]);
        DB::table('localizacoes_historico')->insert([
            ['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'latitude' => 0, 'longitude' => 0, 'capturado_em' => now()->subDays(11)],
            ['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'latitude' => 0, 'longitude' => 0, 'capturado_em' => now()->subDays(5)],
        ]);

        $this->artisan('rastreamento:limpar-historico')->assertSuccessful();

        $this->assertSame(1, DB::table('localizacoes_historico')->count());
    }
}
