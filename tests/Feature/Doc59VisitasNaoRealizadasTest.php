<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Visitas planejadas não realizadas (docs/59): cancelar exige justificativa e deixa rastro, e a
 * fila "vencidas" usa o fuso da empresa.
 */
class Doc59VisitasNaoRealizadasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function os(Empresa $empresa, array $atributos = []): OrdemServico
    {
        return OrdemServico::create($atributos + [
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $empresa->id])->id,
            'origem' => 'MANUAL',
            'obrigatoria' => true,
            'prazo_inicio' => now()->subDays(3),
            'prazo_fim' => now()->subDays(2),
            'status' => 'PENDENTE',
        ]);
    }

    public function test_cancelar_sem_justificativa_e_recusado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = $this->os($empresa);
        Sanctum::actingAs($admin);

        $this->putJson("/api/ordens-servico/{$os->uuid}", ['status' => 'CANCELADA'])
            ->assertInvalid(['responsavel_nao_execucao', 'motivo_uuid', 'motivo_texto']);
        $this->assertDatabaseHas('ordens_servico', ['id' => $os->id, 'status' => 'PENDENTE']);
    }

    public function test_cancelar_com_justificativa_grava_rastro_e_historico(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $motivo = MotivoNaoExecucao::create(['empresa_id' => $empresa->id, 'descricao' => 'Promotor faltou']);
        $os = $this->os($empresa);
        Sanctum::actingAs($admin);

        $resposta = $this->putJson("/api/ordens-servico/{$os->uuid}", [
            'status' => 'CANCELADA',
            'responsavel_nao_execucao' => 'PROMOTOR',
            'motivo_uuid' => $motivo->uuid,
            'motivo_texto' => 'Sem aviso prévio',
        ])->assertOk();

        $resposta->assertJsonPath('ordem_servico.status', 'CANCELADA')
            ->assertJsonPath('ordem_servico.responsavel_nao_execucao', 'PROMOTOR')
            ->assertJsonPath('ordem_servico.motivo_cancelamento.descricao', 'Promotor faltou')
            ->assertJsonPath('ordem_servico.motivo_cancelamento_texto', 'Sem aviso prévio')
            ->assertJsonPath('ordem_servico.cancelada_por.id', $admin->uuid);
        $this->assertNotNull($resposta->json('ordem_servico.cancelada_em'));
        $this->assertDatabaseHas('ordem_servico_historicos', ['ordem_servico_id' => $os->id, 'usuario_id' => $admin->id, 'acao' => 'CANCELADA']);
    }

    public function test_motivo_de_outra_empresa_e_recusado(): void
    {
        $empresa = Empresa::factory()->create();
        $outra = Empresa::factory()->create();
        $motivoAlheio = MotivoNaoExecucao::create(['empresa_id' => $outra->id, 'descricao' => 'Alheio']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = $this->os($empresa);
        Sanctum::actingAs($admin);

        $this->putJson("/api/ordens-servico/{$os->uuid}", [
            'status' => 'CANCELADA', 'responsavel_nao_execucao' => 'LOJA', 'motivo_uuid' => $motivoAlheio->uuid,
        ])->assertInvalid(['motivo_uuid']);
    }

    public function test_lote_exige_justificativa_e_aplica_a_todas(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $a = $this->os($empresa);
        $b = $this->os($empresa);
        Sanctum::actingAs($admin);

        $this->postJson('/api/ordens-servico/cancelar-em-lote', ['uuids' => [$a->uuid, $b->uuid]])
            ->assertInvalid(['responsavel_nao_execucao', 'motivo_uuid', 'motivo_texto']);

        $this->postJson('/api/ordens-servico/cancelar-em-lote', [
            'uuids' => [$a->uuid, $b->uuid], 'responsavel_nao_execucao' => 'EMPRESA', 'motivo_texto' => 'Rota refeita',
        ])->assertOk()->assertJsonPath('canceladas', 2);

        $this->assertDatabaseHas('ordens_servico', ['id' => $a->id, 'status' => 'CANCELADA', 'responsavel_nao_execucao' => 'EMPRESA']);
        $this->assertSame(2, \App\Models\OrdemServicoHistorico::count());
    }

    public function test_promotor_cancela_a_propria_e_fica_registrado(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = $this->os($empresa, ['usuario_id' => $promotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar", ['motivo_texto' => 'Carro quebrou'])
            ->assertOk()
            ->assertJsonPath('ordem_servico.status', 'CANCELADA')
            ->assertJsonPath('ordem_servico.responsavel_nao_execucao', 'PROMOTOR')
            ->assertJsonPath('ordem_servico.cancelada_por.id', $promotor->uuid);
    }

    public function test_fila_de_vencidas_usa_o_dia_da_empresa(): void
    {
        // 02:00 UTC de 24/09 = 22:00 de 23/09 em Manaus; "hoje" = 23/09 00:00 local = 04:00 UTC de 23/09.
        Carbon::setTestNow(Carbon::parse('2026-09-24 02:00:00', 'UTC'));
        $empresa = Empresa::factory()->create(['fuso' => 'America/Manaus']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);

        $ontem = $this->os($empresa, ['prazo_inicio' => '2026-09-22 12:00:00', 'prazo_fim' => '2026-09-22 20:00:00']);
        $hoje = $this->os($empresa, ['prazo_inicio' => '2026-09-23 12:00:00', 'prazo_fim' => '2026-09-23 20:00:00']);
        $concluida = $this->os($empresa, ['prazo_fim' => '2026-09-20 20:00:00', 'status' => 'CONCLUIDA']);
        Sanctum::actingAs($admin);

        $uuids = collect($this->getJson('/api/ordens-servico?vencidas=1')->assertOk()->json('ordens_servico'))->pluck('id');

        $this->assertTrue($uuids->contains($ontem->uuid));
        $this->assertFalse($uuids->contains($hoje->uuid), 'OS de hoje ainda não está vencida');
        $this->assertFalse($uuids->contains($concluida->uuid));
    }

    public function test_catalogo_de_motivos_por_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $outra = Empresa::factory()->create();
        MotivoNaoExecucao::create(['empresa_id' => $outra->id, 'descricao' => 'De outra empresa']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $criado = $this->postJson('/api/motivos-nao-execucao', ['descricao' => 'Loja fechada'])->assertCreated()->json('motivo_nao_execucao');

        $lista = $this->getJson('/api/motivos-nao-execucao')->assertOk()->json('motivos_nao_execucao');
        $this->assertCount(1, $lista);
        $this->assertSame('Loja fechada', $lista[0]['descricao']);

        $this->deleteJson("/api/motivos-nao-execucao/{$criado['id']}")->assertNoContent();
        $this->assertDatabaseHas('motivos_nao_execucao', ['uuid' => $criado['id'], 'ativo' => false]);
    }
}
