<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/07-ORDEM-DE-SERVICO.md — compromisso de visita que o gestor direciona a um promotor
 * (ou deixa em fila aberta, usuario_id null), separado de Visita de propósito: uma OS pode
 * expirar/ser cancelada sem nunca virar execução real.
 */
class OrdemServicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_ordem_servico_manual_para_promotor_especifico(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ordens-servico', [
            'ponto_venda_uuid' => $pdv->uuid,
            'usuario_uuid' => $promotor->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addDays(3)->toDateTimeString(),
            'observacao' => 'Focar na ponta de gôndola',
        ]);

        $response->assertCreated()
            ->assertJsonPath('ordem_servico.origem', 'MANUAL')
            ->assertJsonPath('ordem_servico.status', 'PENDENTE')
            ->assertJsonPath('ordem_servico.obrigatoria', true)
            ->assertJsonPath('ordem_servico.usuario.id', $promotor->uuid)
            ->assertJsonPath('ordem_servico.ponto_venda.id', $pdv->uuid);
    }

    public function test_admin_cria_ordem_servico_manual_com_tipo_prioridade_e_horario(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ordens-servico', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo_visita_uuid' => $tipo->uuid,
            'prioridade' => 'ALTA',
            'horario_previsto' => '14:30',
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('ordem_servico.tipo_visita.id', $tipo->uuid)
            ->assertJsonPath('ordem_servico.prioridade', 'ALTA')
            ->assertJsonPath('ordem_servico.horario_previsto', '14:30');
    }

    public function test_criar_sem_usuario_fica_em_fila_aberta(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ordens-servico', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertCreated()->assertJsonPath('ordem_servico.usuario', null);
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/ordens-servico', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_promotor_ve_apenas_as_suas_e_a_fila_aberta(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $minha = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id]);
        $filaAberta = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => null]);
        OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $outroPromotor->id]);

        Sanctum::actingAs($promotor);

        $response = $this->getJson('/api/ordens-servico')->assertOk();

        $ids = collect($response->json('ordens_servico'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$minha->uuid, $filaAberta->uuid])->sort()->values()->all(), $ids);
    }

    public function test_admin_atualiza_prazo_promotor_e_observacao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        Sanctum::actingAs($admin);

        $novoPrazo = now()->addWeek()->toDateTimeString();

        $response = $this->putJson("/api/ordens-servico/{$os->uuid}", [
            'usuario_uuid' => $promotor->uuid,
            'prazo_fim' => $novoPrazo,
            'observacao' => 'Prorrogado',
        ]);

        $response->assertOk()
            ->assertJsonPath('ordem_servico.usuario.id', $promotor->uuid)
            ->assertJsonPath('ordem_servico.observacao', 'Prorrogado');
    }

    public function test_cancelar_via_update_de_status(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/ordens-servico/{$os->uuid}", ['status' => 'CANCELADA'])
            ->assertOk()
            ->assertJsonPath('ordem_servico.status', 'CANCELADA');
    }

    public function test_update_rejeita_status_gerido_pelo_fluxo_de_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/ordens-servico/{$os->uuid}", ['status' => 'CONCLUIDA'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_checkin_com_ordem_servico_marca_em_andamento_e_vincula_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('visita.ordem_servico.id', $os->uuid);
        $this->assertSame('EM_ANDAMENTO', $os->fresh()->status->value);
        $this->assertNotNull($os->fresh()->visita_id);
    }

    public function test_checkout_conclui_ordem_servico_vinculada(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);
        Sanctum::actingAs($promotor);

        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->json('visita.id');

        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertOk();

        $this->assertSame('CONCLUIDA', $os->fresh()->status->value);
    }

    public function test_checkin_rejeita_ordem_servico_de_outro_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $outroPromotor->id,
        ]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('ordem_servico_uuid');
    }

    public function test_checkin_rejeita_ordem_servico_ja_concluida(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->concluida()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('ordem_servico_uuid');
    }

    public function test_checkin_rejeita_ordem_servico_de_outro_ponto_de_venda(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvCorreto = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvErrado = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdvCorreto->id, 'usuario_id' => $promotor->id,
        ]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdvErrado->uuid,
            'latitude' => $pdvErrado->latitude,
            'longitude' => $pdvErrado->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('ordem_servico_uuid');
    }

    public function test_checkin_sem_ordem_servico_continua_espontaneo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()->assertJsonPath('visita.ordem_servico', null);
    }
}
