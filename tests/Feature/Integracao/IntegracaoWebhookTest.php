<?php

namespace Tests\Feature\Integracao;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\IntegracaoWebhook;
use App\Models\IntegracaoWebhookEntrega;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** docs/96 Fase 4 — webhooks das integrações. */
class IntegracaoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        // Sem DNS nos testes: todo nome resolve pra um IP público, e "interno.local" pra rede interna.
        Webhooks::$resolver = fn (string $host) => $host === 'interno.local' ? ['10.0.0.5'] : ['203.0.113.10'];
    }

    protected function tearDown(): void
    {
        Webhooks::$resolver = null;
        parent::tearDown();
    }

    private function integracao(array $escopos = ['visitas:ler', 'rupturas:ler', 'registros:ler']): Integracao
    {
        return Integracao::create(['empresa_id' => $this->empresa->id, 'nome' => 'BI', 'escopos' => $escopos]);
    }

    private function webhook(Integracao $integracao, array $eventos = ['visita.finalizada', 'ruptura.registrada']): IntegracaoWebhook
    {
        return IntegracaoWebhook::create(['integracao_id' => $integracao->id, 'url' => 'https://cliente.example/horus', 'eventos' => $eventos, 'segredo' => 'whsec_teste']);
    }

    private function visita(): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1'])->id,
            'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id])->id,
        ]);
    }

    public function test_admin_cadastra_webhook_e_recebe_o_segredo_uma_vez(): void
    {
        $integracao = $this->integracao();
        Sanctum::actingAs($this->admin);

        $resposta = $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks", ['url' => 'https://cliente.example/horus', 'eventos' => ['visita.finalizada']])
            ->assertCreated()
            ->assertJsonPath('webhook.eventos', ['visita.finalizada']);
        $segredo = $resposta->json('segredo');
        $this->assertStringStartsWith('whsec_', $segredo);

        $lista = $this->getJson("/api/integracoes/{$integracao->uuid}/webhooks")->assertOk()->assertJsonCount(1, 'webhooks');
        $this->assertStringNotContainsString($segredo, $lista->getContent());
        $this->assertNotSame($segredo, IntegracaoWebhook::first()->getRawOriginal('segredo'), 'o segredo fica criptografado no banco');
    }

    public function test_recusa_endereco_interno_e_evento_desconhecido(): void
    {
        $integracao = $this->integracao();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks", ['url' => 'https://interno.local/x', 'eventos' => ['visita.finalizada']])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks", ['url' => 'http://127.0.0.1/x', 'eventos' => ['visita.finalizada']])->assertUnprocessable();
        $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks", ['url' => 'https://cliente.example/x', 'eventos' => ['tudo']])->assertUnprocessable()->assertJsonValidationErrors('eventos.0');
    }

    public function test_gestor_nao_gerencia_webhook_e_admin_de_outra_empresa_nao_ve(): void
    {
        $integracao = $this->integracao();
        $webhook = $this->webhook($integracao);

        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson("/api/integracoes/{$integracao->uuid}/webhooks")->assertForbidden();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => Empresa::factory()->create()->id]));
        $this->getJson("/api/integracoes/{$integracao->uuid}/webhooks")->assertNotFound();
        $this->deleteJson("/api/integracoes/{$integracao->uuid}/webhooks/{$webhook->uuid}")->assertNotFound();
    }

    public function test_visita_finalizada_e_ruptura_entram_na_fila_e_sao_entregues_assinadas(): void
    {
        Http::fake(['cliente.example/*' => Http::response(['ok' => true])]);
        $this->webhook($this->integracao());
        $visita = $this->visita();

        $visita->update(['status' => StatusVisita::FINALIZADA, 'fim_data' => now()]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura']);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'ruptura' => true]);

        $this->assertSame(['visita.finalizada', 'ruptura.registrada'], IntegracaoWebhookEntrega::orderBy('id')->pluck('evento')->all());

        $this->artisan('integracoes:entregar-webhooks')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function (RequisicaoHttp $req) {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $req->header('X-Horus-Assinatura')[0]));

            return $req->header('X-Horus-Evento')[0] === 'visita.finalizada'
                && hash_equals(hash_hmac('sha256', $t.'.'.$req->body(), 'whsec_teste'), $v1)
                && $req['dados']['loja_codigo_externo'] === 'L1';
        });
        $this->assertSame(0, IntegracaoWebhookEntrega::whereNull('entregue_em')->count());
    }

    public function test_evento_sem_permissao_nao_e_enviado(): void
    {
        $this->webhook($this->integracao(['visitas:ler']), ['visita.finalizada', 'ruptura.registrada']);
        $visita = $this->visita();
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura']);

        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'ruptura' => true]);

        $this->assertSame(0, IntegracaoWebhookEntrega::count());
    }

    public function test_falha_reagenda_e_muitas_falhas_desligam_o_webhook(): void
    {
        Http::fake(['cliente.example/*' => Http::response('fora do ar', 503)]);
        $webhook = $this->webhook($this->integracao());
        $this->visita()->update(['status' => StatusVisita::FINALIZADA]);

        $this->artisan('integracoes:entregar-webhooks');
        $entrega = IntegracaoWebhookEntrega::first();
        $this->assertSame(1, $entrega->tentativas);
        $this->assertSame(503, $entrega->ultimo_status);
        $this->assertTrue($entrega->proxima_tentativa_em->isFuture());

        $webhook->forceFill(['falhas_seguidas' => Webhooks::FALHAS_PARA_DESATIVAR - 1])->save();
        $entrega->forceFill(['proxima_tentativa_em' => now()->subMinute()])->save();
        $this->artisan('integracoes:entregar-webhooks');

        $webhook->refresh();
        $this->assertFalse($webhook->ativo);
        $this->assertNotNull($webhook->desativado_motivo);
    }

    public function test_integracao_pausada_ou_revogada_nao_gera_aviso(): void
    {
        $integracao = $this->integracao();
        $this->webhook($integracao);
        $integracao->update(['ativa' => false]);

        $this->visita()->update(['status' => StatusVisita::FINALIZADA]);

        $this->assertSame(0, IntegracaoWebhookEntrega::count());
    }

    public function test_testar_entrega_na_hora_e_reenviar_volta_pra_fila(): void
    {
        Http::fake(['cliente.example/*' => Http::sequence()->push('erro', 500)->push(['ok' => true])]);
        $integracao = $this->integracao();
        $webhook = $this->webhook($integracao);
        Sanctum::actingAs($this->admin);

        $resposta = $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks/{$webhook->uuid}/testar")
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('entrega.ultimo_status', 500);

        $this->postJson("/api/integracoes/{$integracao->uuid}/webhooks/{$webhook->uuid}/entregas/{$resposta->json('entrega.uuid')}/reenviar")->assertOk();
        $this->artisan('integracoes:entregar-webhooks');

        $this->getJson("/api/integracoes/{$integracao->uuid}/webhooks/{$webhook->uuid}/entregas")
            ->assertOk()
            ->assertJsonPath('entregas.0.situacao', 'entregue');
    }
}
