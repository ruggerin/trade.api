<?php

namespace Tests\Feature;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/51-ENVIO-DA-FILA-EM-TEMPO-REAL.md Fase 2 — a hora gravada é a do campo (mandada pelo app),
 * não a da chegada; a da chegada fica em *_recebido_em. APK antigo (sem o campo) segue igual.
 */
class Doc51HorarioDoCampoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $loja;

    protected function setUp(): void
    {
        parent::setUp();
        // "Agora" do servidor: 18:00 UTC. O promotor fez a visita offline às 14:00–15:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'latitude' => -3.1, 'longitude' => -60.0]);
        Sanctum::actingAs($this->promotor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkin(array $extra = []): Visita
    {
        $resposta = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $this->loja->uuid,
            'latitude' => -3.1,
            'longitude' => -60.0,
            ...$extra,
        ])->assertCreated();

        return Visita::where('uuid', $resposta->json('visita.id'))->firstOrFail();
    }

    private function bruto(string $tabela, int $id, string $coluna): ?string
    {
        $valor = DB::table($tabela)->where('id', $id)->value($coluna);

        return $valor === null ? null : substr((string) $valor, 0, 19);
    }

    public function test_checkin_enviado_com_atraso_guarda_a_hora_do_campo_e_a_da_chegada(): void
    {
        // Manaus 10:00 = 14:00 UTC; chegou no servidor às 18:00 UTC.
        $visita = $this->checkin(['inicio_em' => '2026-10-01T10:00:00-04:00']);

        $this->assertSame('2026-10-01 14:00:00', $this->bruto('visitas', $visita->id, 'inicio_data'));
        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $visita->id, 'checkin_recebido_em'));
    }

    public function test_apk_antigo_sem_hora_usa_a_chegada(): void
    {
        $visita = $this->checkin();

        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $visita->id, 'inicio_data'));
    }

    public function test_relogio_adiantado_ou_hora_velha_demais_usa_a_chegada(): void
    {
        $futuro = $this->checkin(['inicio_em' => '2026-10-01T19:00:00Z']);
        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $futuro->id, 'inicio_data'));

        $futuro->update(['status' => StatusVisita::FINALIZADA, 'fim_data' => now()]);
        // 3 dias atrás passa do limite de 48 h (docs/51 §6 decisão 1).
        $velha = $this->checkin(['inicio_em' => '2026-09-28T18:00:00Z']);
        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $velha->id, 'inicio_data'));
    }

    public function test_checkout_guarda_a_hora_do_campo_e_nunca_antes_do_checkin(): void
    {
        $visita = $this->checkin(['inicio_em' => '2026-10-01T14:00:00Z']);

        $this->patchJson("/api/visitas/{$visita->uuid}/checkout", [
            'latitude' => -3.1, 'longitude' => -60.0, 'fim_em' => '2026-10-01T15:00:00Z',
        ])->assertOk()->assertJsonPath('visita.status', 'FINALIZADA');

        $this->assertSame('2026-10-01 15:00:00', $this->bruto('visitas', $visita->id, 'fim_data'));
        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $visita->id, 'checkout_recebido_em'));

        // Relógio que "voltou" pra antes do check-in: checkout fica no próprio check-in.
        $outra = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $this->loja->id,
            'status' => StatusVisita::ABERTA, 'inicio_data' => Carbon::parse('2026-10-01 16:00:00', 'UTC'),
        ]);
        $this->patchJson("/api/visitas/{$outra->uuid}/checkout", [
            'latitude' => -3.1, 'longitude' => -60.0, 'fim_em' => '2026-10-01T15:00:00Z',
        ])->assertOk();
        $this->assertSame('2026-10-01 16:00:00', $this->bruto('visitas', $outra->id, 'fim_data'));
    }

    public function test_checkout_reenviado_de_visita_ja_encerrada_responde_ok_sem_mudar_nada(): void
    {
        // A resposta do 1º checkout se perdeu: o app manda de novo (fila, docs/51 Fase 1).
        $visita = $this->checkin(['inicio_em' => '2026-10-01T14:00:00Z']);
        $payload = ['latitude' => -3.1, 'longitude' => -60.0, 'fim_em' => '2026-10-01T15:00:00Z'];
        $this->patchJson("/api/visitas/{$visita->uuid}/checkout", $payload)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-01 18:05:00', 'UTC'));
        $this->patchJson("/api/visitas/{$visita->uuid}/checkout", $payload)->assertOk()
            ->assertJsonPath('visita.status', 'FINALIZADA');

        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visitas', $visita->id, 'checkout_recebido_em'));

        // Gestor cancelou pelo admin: o checkout atrasado do app também não fica tentando pra sempre.
        $cancelada = $this->checkin();
        $cancelada->update(['status' => StatusVisita::CANCELADA]);
        $this->patchJson("/api/visitas/{$cancelada->uuid}/checkout", $payload)->assertOk()
            ->assertJsonPath('visita.status', 'CANCELADA');
    }

    public function test_registro_offline_guarda_a_hora_em_que_foi_salvo(): void
    {
        $visita = $this->checkin(['inicio_em' => '2026-10-01T14:00:00Z']);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Observação']);

        $resposta = $this->post("/api/visitas/{$visita->uuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'observacao' => 'Gôndola vazia',
            'criado_em' => '2026-10-01T14:20:00Z',
        ], ['Accept' => 'application/json'])->assertCreated();

        $id = DB::table('visita_registros')->where('uuid', $resposta->json('registro.id'))->value('id');
        $this->assertSame('2026-10-01 14:20:00', $this->bruto('visita_registros', $id, 'created_at'));
        $this->assertSame('2026-10-01 18:00:00', $this->bruto('visita_registros', $id, 'recebido_em'));
    }

    public function test_detalhe_da_visita_expoe_quando_chegou(): void
    {
        $visita = $this->checkin(['inicio_em' => '2026-10-01T14:00:00Z']);

        $this->getJson("/api/visitas/{$visita->uuid}")->assertOk()
            ->assertJsonPath('visita.checkin_recebido_em', fn ($v) => str_starts_with((string) $v, '2026-10-01T18:00:00'));
    }
}
