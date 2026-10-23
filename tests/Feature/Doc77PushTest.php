<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Push;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Push notifications (docs/77-PUSH-NOTIFICATIONS.md §3.2): token por aparelho, envio pela Expo
 * nos gatilhos de comentário do gestor e pedido, e limpeza de token de app desinstalado.
 */
class Doc77PushTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    private Empresa $empresa;

    /** O que a Expo responde a cada mensagem do envio — o teste troca pra simular erro. */
    private array $respostaExpo = ['status' => 'ok', 'id' => 'ticket-1'];

    /** @var array<string, mixed> */
    private array $respostaRecibos = ['data' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        config(['services.expo.access_token' => 'token-de-teste']);
        $this->empresa = Empresa::factory()->create();
        Http::fake([
            Push::URL_ENVIO => fn () => Http::response(['data' => [$this->respostaExpo]]),
            Push::URL_RECIBOS => fn () => Http::response($this->respostaRecibos),
        ]);
    }

    /** Request novo de verdade — ver Doc67LicenciamentoTest::novaRequisicao. */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->defaultHeaders = [];
    }

    private function promotor(): Usuario
    {
        return Usuario::factory()->promotor()->create([
            'empresa_id' => $this->empresa->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);
    }

    /** Login de verdade pelo app — o token Sanctum fica preso ao aparelho (docs/67). */
    private function loginApp(Usuario $usuario, string $aparelho): string
    {
        $this->novaRequisicao();

        return $this->withHeaders(['X-Client' => 'mobile'])->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => $aparelho,
            'dispositivo_nome' => "Celular {$aparelho}",
        ])->assertOk()->json('token');
    }

    private function salvarToken(string $tokenSessao, string $tokenPush): TestResponse
    {
        $this->novaRequisicao();

        return $this->withToken($tokenSessao)->withHeaders(['X-Client' => 'mobile'])
            ->putJson('/api/dispositivos/atual/push-token', ['token' => $tokenPush]);
    }

    private function comentarioDoGestor(Usuario $promotor): void
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $visita = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_ruptura' => true]);
        $registro = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'ruptura' => true]);

        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registro->uuid}/comentarios", ['texto' => 'Repor a gôndola'])
            ->assertCreated();
    }

    public function test_app_salva_o_token_no_aparelho_da_sessao(): void
    {
        $promotor = $this->promotor();
        $sessao = $this->loginApp($promotor, 'aparelho-1');

        $this->salvarToken($sessao, self::TOKEN_A)->assertOk();

        $this->assertSame(self::TOKEN_A, Dispositivo::ativo()->where('usuario_id', $promotor->id)->value('push_token'));
    }

    public function test_token_que_nao_e_da_expo_e_recusado(): void
    {
        $sessao = $this->loginApp($this->promotor(), 'aparelho-1');

        $this->salvarToken($sessao, 'qualquer-coisa')->assertUnprocessable();
    }

    public function test_mesmo_celular_com_outro_login_tira_o_token_do_login_anterior(): void
    {
        $ana = $this->promotor();
        $bia = $this->promotor();
        $this->salvarToken($this->loginApp($ana, 'aparelho-ana'), self::TOKEN_A)->assertOk();

        // Bia loga em outro vínculo, mas o celular (token Expo) é o mesmo.
        $this->salvarToken($this->loginApp($bia, 'aparelho-bia'), self::TOKEN_A)->assertOk();

        $this->assertNull(Dispositivo::ativo()->where('usuario_id', $ana->id)->value('push_token'));
        $this->assertSame(self::TOKEN_A, Dispositivo::ativo()->where('usuario_id', $bia->id)->value('push_token'));
    }

    public function test_logout_apaga_o_token(): void
    {
        $promotor = $this->promotor();
        $sessao = $this->loginApp($promotor, 'aparelho-1');
        $this->salvarToken($sessao, self::TOKEN_A)->assertOk();

        $this->novaRequisicao();
        $this->withToken($sessao)->withHeaders(['X-Client' => 'mobile'])
            ->deleteJson('/api/dispositivos/atual/push-token')->assertNoContent();

        $this->assertNull(Dispositivo::ativo()->where('usuario_id', $promotor->id)->value('push_token'));
    }

    public function test_comentario_do_gestor_manda_push_generico_pro_promotor(): void
    {
        $promotor = $this->promotor();
        $this->salvarToken($this->loginApp($promotor, 'aparelho-1'), self::TOKEN_A)->assertOk();

        $this->comentarioDoGestor($promotor);

        Http::assertSent(function (HttpRequest $request) {
            $mensagem = $request->data()[0] ?? [];

            return $request->url() === Push::URL_ENVIO
                && $request->hasHeader('Authorization', 'Bearer token-de-teste')
                && $mensagem['to'] === self::TOKEN_A
                && $mensagem['channelId'] === Push::CANAL_COMENTARIOS
                && $mensagem['data']['tipo'] === 'comentario'
                // LGPD (docs/77 §3.3): o texto do comentário não vai no push.
                && ! str_contains(json_encode($mensagem), 'Repor a gôndola')
                && ! array_key_exists('dispositivo_id', $mensagem);
        });
        $this->assertSame(1, DB::table('push_recibos')->where('ticket_id', 'ticket-1')->count());
    }

    public function test_sem_token_configurado_nao_envia(): void
    {
        config(['services.expo.access_token' => null]);
        $promotor = $this->promotor();
        $this->salvarToken($this->loginApp($promotor, 'aparelho-1'), self::TOKEN_A)->assertOk();

        $this->comentarioDoGestor($promotor);

        Http::assertNothingSent();
    }

    public function test_promotor_sem_push_token_nao_gera_envio(): void
    {
        $this->comentarioDoGestor($this->promotor());

        Http::assertNothingSent();
    }

    public function test_pedido_previsto_avisa_os_promotores_da_loja(): void
    {
        $promotor = $this->promotor();
        $this->salvarToken($this->loginApp($promotor, 'aparelho-1'), self::TOKEN_A)->assertOk();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        DB::table('promotor_pontos_venda')->insert(['usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id]);

        // Mesmo caminho do ERP no Doc54PedidoPrevisaoTest.
        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $pedidoId = $this->postJson('/api/pedidos', [
            'ponto_venda_uuid' => $pdv->uuid,
            'numero_pedido' => 'PED-7',
            'data_pedido' => now()->subDay()->toDateString(),
            'data_previsao_entrega' => now()->addDays(2)->toDateString(),
            'itens' => [['codigo_externo_produto' => 'X1', 'descricao_produto' => 'Biscoito', 'quantidade' => 24]],
        ])->assertSuccessful()->json('pedido.id');

        Http::assertSent(fn (HttpRequest $r) => ($r->data()[0]['data'] ?? null) === ['tipo' => 'pedido', 'pedido_id' => $pedidoId]
            && $r->data()[0]['title'] === 'Pedido a caminho');
    }

    public function test_device_not_registered_no_envio_apaga_o_token(): void
    {
        $this->respostaExpo = ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']];
        $promotor = $this->promotor();
        $this->salvarToken($this->loginApp($promotor, 'aparelho-1'), self::TOKEN_A)->assertOk();

        $this->comentarioDoGestor($promotor);

        $this->assertNull(Dispositivo::ativo()->where('usuario_id', $promotor->id)->value('push_token'));
    }

    public function test_conferir_recibos_apaga_token_de_app_desinstalado(): void
    {
        $promotor = $this->promotor();
        $this->salvarToken($this->loginApp($promotor, 'aparelho-1'), self::TOKEN_A)->assertOk();
        $dispositivoId = Dispositivo::ativo()->where('usuario_id', $promotor->id)->value('id');
        DB::table('push_recibos')->insert([
            ['dispositivo_id' => $dispositivoId, 'ticket_id' => 'velho', 'created_at' => now()->subMinutes(20)],
            ['dispositivo_id' => $dispositivoId, 'ticket_id' => 'recente', 'created_at' => now()->subMinutes(2)],
        ]);
        $this->respostaRecibos = ['data' => ['velho' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]];

        $this->artisan('push:conferir-recibos')->assertSuccessful();

        $this->assertNull(Dispositivo::find($dispositivoId)->push_token);
        // O recente ainda não é conferido (a Expo leva uns minutos pra ter o recibo).
        $this->assertNull(DB::table('push_recibos')->where('ticket_id', 'recente')->value('conferido_em'));
        $this->assertNotNull(DB::table('push_recibos')->where('ticket_id', 'velho')->value('conferido_em'));
    }
}
