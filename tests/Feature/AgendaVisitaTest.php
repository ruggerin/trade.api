<?php

namespace Tests\Feature;

use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/10-AGENDA-VISITA.md §3.2 — regra recorrente (semanal) ou pontual (data única) que define
 * a rotina de um promotor num PDV; o comando ordens-servico:gerar-por-agenda é testado à parte
 * em GerarOrdensServicoPorAgendaTest.
 */
class AgendaVisitaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_agenda_semanal(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $tipo = TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/agendas-visita', [
            'ponto_venda_uuid' => $pdv->uuid,
            'usuario_uuid' => $promotor->uuid,
            'tipo_visita_uuid' => $tipo->uuid,
            'prioridade' => 'ALTA',
            'recorrencia' => 'SEMANAL',
            'dia_semana' => 1,
            'horario_previsto' => '09:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('agenda_visita.ponto_venda.id', $pdv->uuid)
            ->assertJsonPath('agenda_visita.usuario.id', $promotor->uuid)
            ->assertJsonPath('agenda_visita.tipo_visita.id', $tipo->uuid)
            ->assertJsonPath('agenda_visita.prioridade', 'ALTA')
            ->assertJsonPath('agenda_visita.recorrencia', 'SEMANAL')
            ->assertJsonPath('agenda_visita.dia_semana', 1);
    }

    public function test_admin_cria_agenda_de_data_unica(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/agendas-visita', [
            'ponto_venda_uuid' => $pdv->uuid,
            'usuario_uuid' => $promotor->uuid,
            'recorrencia' => 'DATA_UNICA',
            'data' => now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('agenda_visita.recorrencia', 'DATA_UNICA')
            ->assertJsonPath('agenda_visita.dia_semana', null);
    }

    public function test_rejeita_dia_semana_ausente_quando_recorrencia_semanal(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($admin);

        $this->postJson('/api/agendas-visita', [
            'ponto_venda_uuid' => $pdv->uuid,
            'usuario_uuid' => $promotor->uuid,
            'recorrencia' => 'SEMANAL',
        ])->assertStatus(422)->assertJsonValidationErrors('dia_semana');
    }

    public function test_rejeita_promotor_nao_vinculado_ao_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        // Sem attach — promotor não atende este PDV.
        Sanctum::actingAs($admin);

        $this->postJson('/api/agendas-visita', [
            'ponto_venda_uuid' => $pdv->uuid,
            'usuario_uuid' => $promotor->uuid,
            'recorrencia' => 'SEMANAL',
            'dia_semana' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('usuario_uuid');
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->getJson('/api/agendas-visita')->assertForbidden();
    }

    public function test_filtra_por_ponto_de_venda(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv2 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        AgendaVisita::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv1->id]);
        AgendaVisita::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv2->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/agendas-visita?ponto_venda_uuid={$pdv1->uuid}")->assertOk();

        $this->assertCount(1, $response->json('agendas_visita'));
        $this->assertSame($pdv1->uuid, $response->json('agendas_visita.0.ponto_venda.id'));
    }

    public function test_update_troca_recorrencia_e_limpa_o_campo_do_outro_tipo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->semanal(1)->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $novaData = now()->addWeek()->toDateString();
        $this->putJson("/api/agendas-visita/{$agenda->uuid}", [
            'recorrencia' => 'DATA_UNICA',
            'data' => $novaData,
        ])->assertOk();

        $agenda->refresh();
        $this->assertNull($agenda->dia_semana);
        $this->assertSame($novaData, $agenda->data->toDateString());
    }

    public function test_destroy_desativa_em_vez_de_apagar(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/agendas-visita/{$agenda->uuid}")->assertNoContent();

        $this->assertFalse($agenda->fresh()->ativo);
    }

    // docs/10-AGENDA-VISITA.md §9 — Relatório de Rota impresso (PDF gerado no backend).
    public function test_admin_gera_relatorio_rota_pdf(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        AgendaVisita::factory()->semanal(2)->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->get("/api/agendas-visita/relatorio-rota?usuario_uuid={$promotor->uuid}");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_relatorio_rota_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->get("/api/agendas-visita/relatorio-rota?usuario_uuid={$promotor->uuid}")->assertForbidden();
    }

    public function test_relatorio_rota_isolado_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        $promotorDeOutraEmpresa = Usuario::factory()->promotor()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($admin);

        $this->get("/api/agendas-visita/relatorio-rota?usuario_uuid={$promotorDeOutraEmpresa->uuid}")
            ->assertNotFound();
    }
}
