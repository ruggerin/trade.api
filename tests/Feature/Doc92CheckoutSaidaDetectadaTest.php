<?php

namespace Tests\Feature;

use App\Enums\CheckoutTipo;
use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\AfastamentoVisita;
use App\Support\Push;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/92 — checkout pela saída detectada: raio no checkout (A), sugestão da saída (B), aviso por
 * push (C) e fechamento automático (D). Visita 08:00 na loja, ele fica até 08:20 e sai andando
 * até ~3 km; "agora" = 09:00. Afastamento padrão: 300 m por 10 min; tolerância de sinal 5 min.
 */
class Doc92CheckoutSaidaDetectadaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $loja;

    private Visita $visita;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 09:00:00');
        $this->withoutDefer();
        Http::fake([Push::URL_ENVIO => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

        $this->empresa = Empresa::factory()->create(['fuso' => 'UTC']);
        $this->parametro('RASTREAMENTO_INTERVALO_SEGUNDOS', '60');
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'latitude' => -3.1, 'longitude' => -60.0]);
        $this->visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::ABERTA,
            'inicio_data' => Carbon::parse('2026-10-23 08:00'),
            'inicio_latitude' => -3.1,
            'inicio_longitude' => -60.0,
            'inicio_distancia_metros' => 5,
            'fim_data' => null,
        ]);
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

    /** Um ponto por minuto, de [lat, lng] a [lat, lng]. */
    private function trajeto(string $de, string $ate, array $origem, array $destino): void
    {
        $inicio = Carbon::parse("2026-10-23 {$de}");
        $minutos = (int) $inicio->diffInMinutes(Carbon::parse("2026-10-23 {$ate}"));
        for ($m = 0; $m <= $minutos; $m++) {
            $f = $minutos ? $m / $minutos : 0;
            DB::table('localizacoes_historico')->insert([
                'empresa_id' => $this->empresa->id,
                'usuario_id' => $this->promotor->id,
                'latitude' => $origem[0] + ($destino[0] - $origem[0]) * $f,
                'longitude' => $origem[1] + ($destino[1] - $origem[1]) * $f,
                'capturado_em' => $inicio->copy()->addMinutes($m),
            ]);
        }
    }

    /** Fica na loja até 08:20 e sai: ~1,1 km às 08:30, ~3 km às 09:00. */
    private function saiuDaLoja(): void
    {
        $this->trajeto('08:00', '08:20', [-3.1, -60.0], [-3.1, -60.0]);
        $this->trajeto('08:21', '09:00', [-3.1003, -60.0], [-3.127, -60.0]);
    }

    private function comoPromotor(): void
    {
        Sanctum::actingAs($this->promotor);
    }

    private function checkout(array $dados = [])
    {
        $this->comoPromotor();

        // Posição de agora, ~3 km da loja.
        return $this->patchJson("/api/visitas/{$this->visita->uuid}/checkout", ['latitude' => -3.127, 'longitude' => -60.0, ...$dados]);
    }

    // ---- Saída detectada (§3) ----

    public function test_saida_e_a_ultima_posicao_perto_da_loja(): void
    {
        $this->saiuDaLoja();

        $saida = AfastamentoVisita::saidaDetectada($this->visita->fresh());

        $this->assertNotNull($saida);
        // Último ponto a até 300 m (afastamento) antes de ele ficar longe.
        $this->assertTrue($saida['saiu_em']->between(Carbon::parse('2026-10-23 08:20'), Carbon::parse('2026-10-23 08:24')));
        $this->assertLessThanOrEqual(300, $saida['distancia_metros']);
        $this->assertNull($saida['dentro_do_raio']);
        $this->assertGreaterThan(2500, $saida['distancia_atual_metros']);
    }

    public function test_quem_voltou_pra_loja_nao_tem_saida(): void
    {
        $this->trajeto('08:00', '08:20', [-3.1, -60.0], [-3.1, -60.0]);
        $this->trajeto('08:21', '08:40', [-3.1003, -60.0], [-3.12, -60.0]);
        $this->trajeto('08:41', '09:00', [-3.12, -60.0], [-3.1, -60.0]);

        $this->assertNull(AfastamentoVisita::saidaDetectada($this->visita->fresh()));
    }

    public function test_sem_posicao_recente_nao_afirma_saida(): void
    {
        $this->trajeto('08:00', '08:20', [-3.1, -60.0], [-3.1, -60.0]);
        $this->trajeto('08:21', '08:40', [-3.1003, -60.0], [-3.12, -60.0]);

        // Última posição 08:40, agora 09:00 (tolerância 5 min): pode ter voltado sem sinal.
        $this->assertNull(AfastamentoVisita::saidaDetectada($this->visita->fresh()));
    }

    public function test_registro_feito_depois_empurra_a_hora_da_saida(): void
    {
        $this->saiuDaLoja();
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Foto']);
        $registro = new VisitaRegistro(['visita_id' => $this->visita->id, 'tipo_registro_id' => $tipo->id]);
        $registro->created_at = Carbon::parse('2026-10-23 08:35');
        $registro->save();

        $saida = AfastamentoVisita::saidaDetectada($this->visita->fresh());

        $this->assertTrue($saida['saiu_em']->eq(Carbon::parse('2026-10-23 08:35')));
    }

    public function test_rota_da_saida_so_responde_com_a_sugestao_ligada(): void
    {
        $this->saiuDaLoja();
        $this->comoPromotor();
        $this->getJson("/api/visitas/{$this->visita->uuid}/saida-detectada")->assertOk()->assertJsonPath('saida', null);

        $this->parametro('CHECKOUT_SUGERIR_SAIDA', 'true');
        $this->getJson("/api/visitas/{$this->visita->uuid}/saida-detectada")->assertOk()
            ->assertJsonPath('saida.dentro_do_raio', null)
            ->assertJsonPath('fora_do_raio', 'REGISTRA');
    }

    public function test_gestor_ve_a_saida_pra_forcar_checkout_mesmo_sem_a_sugestao(): void
    {
        $this->saiuDaLoja();

        $semPermissao = Perfil::factory()->comPermissoes([])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $semPermissao->id]));
        $this->getJson("/api/visitas/{$this->visita->uuid}/saida-detectada")->assertForbidden();

        $comPermissao = Perfil::factory()->comPermissoes([Permissao::VISITAS_INTERVIR->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $comPermissao->id]));
        $this->assertNotNull($this->getJson("/api/visitas/{$this->visita->uuid}/saida-detectada")->assertOk()->json('saida.saiu_em'));
    }

    // ---- Checkout (A + B) ----

    public function test_checkout_normal_grava_tipo_promotor_e_sem_raio_nao_marca(): void
    {
        $this->saiuDaLoja();

        $this->checkout()->assertOk()
            ->assertJsonPath('visita.checkout_tipo', 'PROMOTOR')
            ->assertJsonPath('visita.checkout_no_raio', null)
            ->assertJsonPath('visita.tempo_confirmado_gps', null);
    }

    public function test_usar_saida_detectada_finaliza_no_horario_da_saida(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_SUGERIR_SAIDA', 'true');

        $this->checkout(['usar_saida_detectada' => true])->assertOk()->assertJsonPath('visita.checkout_tipo', 'SAIDA_DETECTADA');

        $visita = $this->visita->fresh();
        $this->assertTrue($visita->fim_data->lt(Carbon::parse('2026-10-23 08:25')));
        $this->assertLessThanOrEqual(300, $visita->fim_distancia_metros);
    }

    public function test_sem_a_sugestao_ligada_o_pedido_de_saida_e_ignorado(): void
    {
        $this->saiuDaLoja();

        $this->checkout(['usar_saida_detectada' => true])->assertOk()->assertJsonPath('visita.checkout_tipo', 'PROMOTOR');
        $this->assertTrue($this->visita->fresh()->fim_data->eq(now()));
    }

    public function test_raio_avisa_aceita_e_marca_fora(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_RAIO_METROS', '200');
        $this->parametro('CHECKOUT_FORA_DO_RAIO', 'AVISA');

        $this->checkout()->assertOk()
            ->assertJsonPath('visita.checkout_no_raio', false)
            ->assertJsonPath('visita.tempo_confirmado_gps', false);
    }

    public function test_raio_bloqueia_recusa_fora(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_RAIO_METROS', '200');
        $this->parametro('CHECKOUT_FORA_DO_RAIO', 'BLOQUEIA');

        $this->checkout()->assertStatus(422)->assertJsonPath('fora_do_raio', true)->assertJsonPath('raio_metros', 200);
        $this->assertSame(StatusVisita::ABERTA, $this->visita->fresh()->status);
    }

    public function test_raio_bloqueia_com_saida_detectada_finaliza_sem_voltar_e_confirma_o_tempo(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_RAIO_METROS', '200');
        $this->parametro('CHECKOUT_FORA_DO_RAIO', 'BLOQUEIA');
        $this->parametro('CHECKOUT_SUGERIR_SAIDA', 'true');

        $this->checkout(['usar_saida_detectada' => true])->assertOk()
            ->assertJsonPath('visita.checkout_tipo', 'SAIDA_DETECTADA')
            ->assertJsonPath('visita.checkout_no_raio', true)
            ->assertJsonPath('visita.tempo_confirmado_gps', true);
    }

    public function test_gestor_forca_checkout_sem_passar_pelo_raio(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_RAIO_METROS', '200');
        $this->parametro('CHECKOUT_FORA_DO_RAIO', 'BLOQUEIA');
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson("/api/visitas/{$this->visita->uuid}/forcar-checkout", ['fim_data' => '2026-10-23T08:21:00Z', 'motivo' => 'Esqueceu'])
            ->assertOk()->assertJsonPath('visita.checkout_tipo', 'ADMIN');
    }

    // ---- Aviso (C) e fechamento automático (D) ----

    private function comAparelho(): void
    {
        config(['services.expo.access_token' => 'token-de-teste']);
        $dispositivo = Dispositivo::create([
            'usuario_id' => $this->promotor->id,
            'empresa_id' => $this->empresa->id,
            'identificador_hash' => hash('sha256', 'aparelho'),
            'nome' => 'Celular',
            'plataforma' => 'android',
            'vinculado_em' => now(),
            'ultimo_acesso_em' => now(),
        ]);
        $dispositivo->forceFill(['push_token' => 'ExponentPushToken[abc]'])->save();
    }

    public function test_aviso_por_push_vai_uma_vez_so(): void
    {
        $this->saiuDaLoja();
        $this->comAparelho();
        $this->parametro('CHECKOUT_AVISO_SAIDA_MINUTOS', '10');

        $this->artisan('visitas:monitorar-saida')->assertSuccessful();
        $this->artisan('visitas:monitorar-saida')->assertSuccessful();

        $this->assertNotNull($this->visita->fresh()->saida_avisada_em);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ($r->data()[0]['data']['tipo'] ?? null) === 'saida_detectada' && ($r->data()[0]['categoryId'] ?? null) === 'saida_detectada');
        $this->assertSame(StatusVisita::ABERTA, $this->visita->fresh()->status);
    }

    public function test_sem_parametro_o_comando_nao_faz_nada(): void
    {
        $this->saiuDaLoja();
        $this->comAparelho();

        $this->artisan('visitas:monitorar-saida')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(StatusVisita::ABERTA, $this->visita->fresh()->status);
    }

    public function test_fechamento_automatico_no_horario_da_saida(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_AUTOMATICO_MINUTOS', '30');

        $this->artisan('visitas:monitorar-saida')->assertSuccessful();

        $visita = $this->visita->fresh();
        $this->assertSame(StatusVisita::FINALIZADA, $visita->status);
        $this->assertSame(CheckoutTipo::AUTOMATICO, $visita->checkout_tipo);
        $this->assertTrue($visita->fim_data->lt(Carbon::parse('2026-10-23 08:25')));
    }

    public function test_automatico_respeita_o_minimo_de_30_min(): void
    {
        $this->saiuDaLoja();
        // 10 min sobe pra 30; ele saiu às ~08:21, agora 08:45 (24 min): ainda não fecha.
        Carbon::setTestNow('2026-10-23 08:45:00');
        DB::table('localizacoes_historico')->where('capturado_em', '>', now())->delete();
        $this->parametro('CHECKOUT_AUTOMATICO_MINUTOS', '10');

        $this->artisan('visitas:monitorar-saida')->assertSuccessful();

        $this->assertSame(StatusVisita::ABERTA, $this->visita->fresh()->status);
    }

    public function test_ainda_estou_na_loja_impede_o_fechamento_automatico(): void
    {
        $this->saiuDaLoja();
        $this->parametro('CHECKOUT_AUTOMATICO_MINUTOS', '30');
        $this->comoPromotor();
        $this->postJson("/api/visitas/{$this->visita->uuid}/ainda-na-loja")->assertNoContent();

        $this->artisan('visitas:monitorar-saida')->assertSuccessful();

        $this->assertSame(StatusVisita::ABERTA, $this->visita->fresh()->status);
        $this->assertNotNull($this->visita->fresh()->saida_contestada_em);
    }

    public function test_outro_promotor_nao_contesta_a_saida(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson("/api/visitas/{$this->visita->uuid}/ainda-na-loja")->assertForbidden();
    }

    public function test_reincidencia_conta_checkout_esquecido_por_promotor(): void
    {
        $base = ['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::FINALIZADA, 'inicio_data' => Carbon::parse('2026-10-20 08:00'), 'fim_data' => Carbon::parse('2026-10-20 09:00')];
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::SAIDA_DETECTADA]);
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::SAIDA_DETECTADA, 'checkout_no_raio' => false]);
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::AUTOMATICO]);
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::PROMOTOR, 'checkout_no_raio' => true]);
        // Fora da janela de 30 dias e de outro promotor: não contam.
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::AUTOMATICO, 'inicio_data' => Carbon::parse('2026-08-01 08:00')]);
        Visita::factory()->create([...$base, 'checkout_tipo' => CheckoutTipo::AUTOMATICO,
            'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id])->id]);

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->getJson("/api/usuarios/{$this->promotor->uuid}/reincidencia-checkout")
            ->assertOk()
            ->assertJsonPath('reincidencia.dias', 30)
            ->assertJsonPath('reincidencia.visitas_finalizadas', 4)
            ->assertJsonPath('reincidencia.saida_detectada', 2)
            ->assertJsonPath('reincidencia.automatico', 1)
            ->assertJsonPath('reincidencia.esqueceu', 3)
            ->assertJsonPath('reincidencia.fora_do_raio', 1);
    }

    public function test_reincidencia_exige_permissao_de_usuarios(): void
    {
        $this->comoPromotor();

        $this->getJson("/api/usuarios/{$this->promotor->uuid}/reincidencia-checkout")->assertForbidden();
    }
}
