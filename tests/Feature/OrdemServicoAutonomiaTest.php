<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\ObjetivoVisita;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/13-AGENDA-MOBILE-E-AUTONOMIA.md §4 — o promotor cria/reagenda/cancela a própria
 * OrdemServico ("+ Compromisso" no mobile), aplicando direto (modo autônomo, default) ou
 * virando uma solicitação pendente (modo aprovação, `Parametro` `AGENDA_REQUER_APROVACAO`) até
 * o gestor decidir via aprovar/rejeitar.
 */
class OrdemServicoAutonomiaTest extends TestCase
{
    use RefreshDatabase;

    private function ligarModoAprovacao(Empresa $empresa): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'AGENDA_REQUER_APROVACAO', 'valor' => 'true']);
    }

    // ---- Criação self-service ("+ Compromisso") ----

    public function test_promotor_cria_compromisso_proprio_modo_autonomo_nasce_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]); // sem vínculo, mas modo aberto é o default
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/ordens-servico/minhas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('ordem_servico.status', 'PENDENTE')
            ->assertJsonPath('ordem_servico.usuario.id', $promotor->uuid)
            ->assertJsonPath('ordem_servico.obrigatoria', true);
    }

    public function test_promotor_cria_compromisso_proprio_modo_aprovacao_nasce_aguardando(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarModoAprovacao($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/ordens-servico/minhas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertCreated()->assertJsonPath('ordem_servico.status', 'AGUARDANDO_APROVACAO');
    }

    public function test_promotor_cria_com_tipo_e_objetivo_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        $objetivo = ObjetivoVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/ordens-servico/minhas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo_visita_uuid' => $tipo->uuid,
            'objetivo_visita_uuid' => $objetivo->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('ordem_servico.tipo_visita.id', $tipo->uuid)
            ->assertJsonPath('ordem_servico.objetivo_visita.id', $objetivo->uuid);
    }

    public function test_rejeita_pdv_que_promotor_nao_enxerga(): void
    {
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'PONTOS_VENDA_RESTRITO_A_VINCULO', 'valor' => 'true']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]); // sem vínculo, modo restrito
        Sanctum::actingAs($promotor);

        $this->postJson('/api/ordens-servico/minhas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('ponto_venda_uuid');
    }

    // ---- Reagendar ----

    public function test_promotor_reagenda_propria_os_modo_autonomo_aplica_direto(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'usuario_id' => $promotor->id]);
        Sanctum::actingAs($promotor);

        // Meio-dia fixo: prazo_fim é sempre "novoPrazo + 2h" — perto da meia-noite isso viraria
        // dia seguinte e o isSameDay abaixo ia falhar por causa da hora do teste, não da regra.
        $novoPrazo = now()->addDays(2)->setTime(12, 0);
        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/reagendar", [
            'prazo_inicio' => $novoPrazo->toDateTimeString(),
            'prazo_fim' => $novoPrazo->copy()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertOk()->assertJsonPath('ordem_servico.status', 'PENDENTE');
        $this->assertTrue($os->fresh()->prazo_fim->isSameDay($novoPrazo));
    }

    public function test_promotor_reagenda_propria_os_modo_aprovacao_vira_solicitacao(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarModoAprovacao($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        // Meio-dia fixo nos dois — cada um vira "+2h" nalgum momento do teste, perto da
        // meia-noite isso cruzaria pro dia seguinte e o isSameDay abaixo falharia pela hora em
        // que o teste rodou, não pela regra sendo testada.
        $prazoOriginal = now()->addDay()->setTime(12, 0);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'usuario_id' => $promotor->id,
            'prazo_inicio' => $prazoOriginal, 'prazo_fim' => $prazoOriginal->copy()->addHours(2),
        ]);
        Sanctum::actingAs($promotor);

        $novoPrazo = now()->addDays(5)->setTime(12, 0);
        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/reagendar", [
            'prazo_inicio' => $novoPrazo->toDateTimeString(),
            'prazo_fim' => $novoPrazo->copy()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertOk()->assertJsonPath('ordem_servico.status', 'REAGENDAMENTO_SOLICITADO');
        $fresh = $os->fresh();
        $this->assertTrue($fresh->prazo_fim->isSameDay($prazoOriginal), 'prazo oficial não deveria mudar ainda');
        $this->assertTrue($fresh->prazo_fim_proposto->isSameDay($novoPrazo));
    }

    public function test_promotor_nao_reagenda_os_de_outro(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'usuario_id' => $outroPromotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/reagendar", [
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_reagendar_os_nao_pendente_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->concluida()->create(['empresa_id' => $empresa->id, 'usuario_id' => $promotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/reagendar", [
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addHours(2)->toDateTimeString(),
        ])->assertStatus(422);
    }

    // ---- Cancelar ----

    public function test_promotor_cancela_propria_os_modo_autonomo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'usuario_id' => $promotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'CANCELADA');
    }

    public function test_promotor_cancela_propria_os_modo_aprovacao_vira_solicitacao(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarModoAprovacao($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'usuario_id' => $promotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'CANCELAMENTO_SOLICITADO');
    }

    public function test_promotor_nao_cancela_os_de_outro(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'usuario_id' => $outroPromotor->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar")->assertForbidden();
    }

    // ---- Aprovar/Rejeitar (gestor) ----

    public function test_gestor_aprova_criacao_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'AGUARDANDO_APROVACAO']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/ordens-servico/{$os->uuid}/aprovar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'PENDENTE');
    }

    public function test_gestor_rejeita_criacao_pendente_vira_cancelada(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'AGUARDANDO_APROVACAO']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'CANCELADA');
    }

    public function test_gestor_rejeita_com_motivo_fica_registrado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'CANCELAMENTO_SOLICITADO']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar", ['motivo' => 'Precisamos dessa visita essa semana']);

        $response->assertOk()->assertJsonPath('ordem_servico.motivo_rejeicao', 'Precisamos dessa visita essa semana');
    }

    public function test_rejeitar_sem_motivo_fica_null(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'CANCELAMENTO_SOLICITADO']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar");

        $response->assertOk()->assertJsonPath('ordem_servico.motivo_rejeicao', null);
    }

    public function test_motivo_rejeicao_e_limpo_quando_promotor_reagenda_de_novo(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarModoAprovacao($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'usuario_id' => $promotor->id, 'motivo_rejeicao' => 'Rejeitado da última vez',
        ]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/reagendar", [
            'prazo_inicio' => now()->addDay()->toDateTimeString(),
            'prazo_fim' => now()->addDay()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertOk()->assertJsonPath('ordem_servico.motivo_rejeicao', null);
    }

    public function test_motivo_rejeicao_visivel_pro_promotor_apos_status_voltar_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'usuario_id' => $promotor->id, 'status' => 'REAGENDAMENTO_SOLICITADO',
            'prazo_inicio_proposto' => now()->addDays(3), 'prazo_fim_proposto' => now()->addDays(3)->addHours(2),
        ]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar", ['motivo' => 'Loja fechada nesse dia'])->assertOk();

        Sanctum::actingAs($promotor);
        $response = $this->getJson('/api/ordens-servico?status=PENDENTE')->assertOk();

        $item = collect($response->json('ordens_servico'))->firstWhere('id', $os->uuid);
        $this->assertSame('Loja fechada nesse dia', $item['motivo_rejeicao']);
    }

    public function test_gestor_aprova_reagendamento_aplica_prazo_proposto(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        // Meio-dia fixo — vira "+2h" no fim (ver abaixo), perto da meia-noite cruzaria pro dia
        // seguinte e o isSameDay falharia pela hora do teste, não pela regra.
        $proposto = now()->addDays(3)->setTime(12, 0);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'status' => 'REAGENDAMENTO_SOLICITADO',
            'prazo_inicio_proposto' => $proposto, 'prazo_fim_proposto' => $proposto->copy()->addHours(2),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/aprovar");

        $response->assertOk()->assertJsonPath('ordem_servico.status', 'PENDENTE');
        $fresh = $os->fresh();
        $this->assertTrue($fresh->prazo_fim->isSameDay($proposto));
        $this->assertNull($fresh->prazo_inicio_proposto);
        $this->assertNull($fresh->prazo_fim_proposto);
    }

    public function test_gestor_rejeita_reagendamento_mantem_prazo_original(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        // Meio-dia fixo nos dois, mesmo motivo dos testes acima.
        $original = now()->addDay()->setTime(12, 0);
        $proposto = now()->addDays(3)->setTime(12, 0);
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'status' => 'REAGENDAMENTO_SOLICITADO',
            'prazo_inicio' => $original, 'prazo_fim' => $original->copy()->addHours(2),
            'prazo_inicio_proposto' => $proposto, 'prazo_fim_proposto' => $proposto->copy()->addHours(2),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar");

        $response->assertOk()->assertJsonPath('ordem_servico.status', 'PENDENTE');
        $fresh = $os->fresh();
        $this->assertTrue($fresh->prazo_fim->isSameDay($original));
        $this->assertNull($fresh->prazo_fim_proposto);
    }

    public function test_gestor_aprova_cancelamento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'CANCELAMENTO_SOLICITADO']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/ordens-servico/{$os->uuid}/aprovar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'CANCELADA');
    }

    public function test_gestor_rejeita_cancelamento_volta_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'CANCELAMENTO_SOLICITADO']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar")
            ->assertOk()->assertJsonPath('ordem_servico.status', 'PENDENTE');
    }

    public function test_aprovar_sem_solicitacao_pendente_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id]); // já PENDENTE normal
        Sanctum::actingAs($admin);

        $this->postJson("/api/ordens-servico/{$os->uuid}/aprovar")->assertStatus(422);
    }

    public function test_promotor_nao_acessa_aprovar_ou_rejeitar(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'AGUARDANDO_APROVACAO']);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/ordens-servico/{$os->uuid}/aprovar")->assertForbidden();
        $this->postJson("/api/ordens-servico/{$os->uuid}/rejeitar")->assertForbidden();
    }

    // ---- Listagem: múltiplos status + janela de prazo ----

    public function test_lista_aceita_multiplos_status(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'status' => 'PENDENTE']);
        OrdemServico::factory()->concluida()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->cancelada()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/ordens-servico?status[]=PENDENTE&status[]=CONCLUIDA')->assertOk();

        $this->assertCount(2, $response->json('ordens_servico'));
    }

    public function test_lista_filtra_por_janela_de_prazo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'prazo_fim' => now()]);
        OrdemServico::factory()->create(['empresa_id' => $empresa->id, 'prazo_fim' => now()->addDays(10)]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/ordens-servico?prazo_de='.now()->toDateString().'&prazo_ate='.now()->addDays(6)->toDateString())
            ->assertOk();

        $this->assertCount(1, $response->json('ordens_servico'));
    }
}
