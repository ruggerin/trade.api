<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md — gestor (com permissão visitas.intervir) ou
 * admin podendo cancelar visita, forçar checkout com horário real e corrigir horários, sempre
 * com motivo obrigatório e log de auditoria (visita_intervencoes).
 */
class IntervencaoAdministrativaTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        return [$empresa, $admin, $promotor, $pdv];
    }

    private function visitaAberta(Empresa $empresa, PontoVenda $pdv, Usuario $promotor): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'inicio_data' => now()->subHours(3),
        ]);
    }

    public function test_admin_cancela_visita_aberta_e_grava_log(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/visitas/{$visita->uuid}/cancelar", [
            'motivo' => 'Promotor abriu a visita por engano.',
        ]);

        $response->assertOk()->assertJsonPath('visita.status', 'CANCELADA');

        $this->assertDatabaseHas('visitas', ['id' => $visita->id, 'status' => 'CANCELADA']);
        $this->assertDatabaseHas('visita_intervencoes', [
            'visita_id' => $visita->id,
            'usuario_id' => $admin->id,
            'acao' => 'CANCELAMENTO',
            'motivo' => 'Promotor abriu a visita por engano.',
        ]);
    }

    public function test_cancelar_visita_com_ordem_servico_volta_os_para_pendente(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'status' => 'EM_ANDAMENTO',
        ]);
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);
        $visita->update(['ordem_servico_id' => $os->id]);
        $os->update(['visita_id' => $visita->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Visita não aconteceu.'])
            ->assertOk();

        $this->assertDatabaseHas('ordens_servico', [
            'id' => $os->id, 'status' => 'PENDENTE', 'visita_id' => null,
        ]);
    }

    public function test_cancelar_visita_ja_cancelada_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->cancelada()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Tentando de novo.'])
            ->assertStatus(422);
    }

    public function test_admin_forca_checkout_com_horario_real_sem_gps(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);
        $fim = now()->subHours(2)->startOfMinute();

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Promotor esqueceu de bater a saída.',
            'fim_data' => $fim->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('visita.status', 'FINALIZADA')
            ->assertJsonPath('visita.checkout_tipo', 'ADMIN')
            ->assertJsonPath('visita.fim_latitude', null);

        $visita->refresh();
        $this->assertTrue($visita->fim_data->eq($fim));
        $this->assertNull($visita->fim_distancia_metros);
        $this->assertDatabaseHas('visita_intervencoes', [
            'visita_id' => $visita->id, 'acao' => 'CHECKOUT_FORCADO',
        ]);
    }

    public function test_forcar_checkout_com_fim_data_antes_do_inicio_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor); // inicio_data = agora - 3h

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Horário qualquer.',
            'fim_data' => now()->subHours(5)->toIso8601String(),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('visita_intervencoes', ['visita_id' => $visita->id]);
    }

    public function test_forcar_checkout_com_fim_data_futura_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Horário no futuro.',
            'fim_data' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_forcar_checkout_de_visita_finalizada_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Já está fechada.',
            'fim_data' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_forcar_checkout_com_ordem_servico_conclui_a_os(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'status' => 'EM_ANDAMENTO',
        ]);
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);
        $visita->update(['ordem_servico_id' => $os->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Fechando pelo gestor.',
            'fim_data' => now()->subHour()->toIso8601String(),
        ])->assertOk();

        $this->assertDatabaseHas('ordens_servico', ['id' => $os->id, 'status' => 'CONCLUIDA']);
    }

    public function test_admin_corrige_horarios_de_visita_finalizada(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'inicio_data' => now()->subHours(4),
            'fim_data' => now()->subHours(3),
        ]);
        $novoInicio = now()->subHours(4)->subMinutes(30)->startOfMinute();
        $novoFim = now()->subHours(1)->startOfMinute();

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/visitas/{$visita->uuid}/horarios", [
            'motivo' => 'Promotor bateu a saída no carro, meia hora depois.',
            'inicio_data' => $novoInicio->toIso8601String(),
            'fim_data' => $novoFim->toIso8601String(),
        ]);

        $response->assertOk();
        $visita->refresh();
        $this->assertTrue($visita->inicio_data->eq($novoInicio));
        $this->assertTrue($visita->fim_data->eq($novoFim));
        $this->assertSame('FINALIZADA', $visita->status->value);
        $this->assertDatabaseHas('visita_intervencoes', [
            'visita_id' => $visita->id, 'acao' => 'CORRECAO_HORARIO',
        ]);
    }

    public function test_corrigir_horarios_sem_nenhum_horario_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/visitas/{$visita->uuid}/horarios", ['motivo' => 'Sem horário nenhum.'])
            ->assertStatus(422);
    }

    public function test_corrigir_horarios_de_visita_aberta_retorna_422(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/visitas/{$visita->uuid}/horarios", [
            'motivo' => 'Visita ainda aberta.',
            'fim_data' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_motivo_e_obrigatorio(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", [])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'ok'])
            ->assertStatus(422)->assertJsonValidationErrors('motivo'); // min:5
    }

    public function test_gestor_sem_permissao_nao_pode_intervir(): void
    {
        [$empresa, , $promotor, $pdv] = $this->cenario();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($gestor);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Sem permissão.'])
            ->assertForbidden();
    }

    public function test_gestor_com_permissao_pode_intervir(): void
    {
        [$empresa, , $promotor, $pdv] = $this->cenario();
        $perfil = Perfil::factory()->comPermissoes(['visitas.intervir'])->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($gestor);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Gestor autorizado.'])
            ->assertOk();
    }

    public function test_promotor_nao_pode_intervir_nem_na_propria_visita(): void
    {
        [$empresa, , $promotor, $pdv] = $this->cenario();
        // Mesmo com a permissão marcada num perfil, EnsurePermissao ignora perfil pra PROMOTOR.
        $perfil = Perfil::factory()->comPermissoes(['visitas.intervir'])->create(['empresa_id' => $empresa->id]);
        $promotor->update(['perfil_id' => $perfil->id]);
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($promotor);
        $this->postJson("/api/visitas/{$visita->uuid}/cancelar", ['motivo' => 'Promotor tentando.'])
            ->assertForbidden();
    }

    public function test_intervencoes_e_checkout_tipo_aparecem_no_get_da_visita(): void
    {
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = $this->visitaAberta($empresa, $pdv, $promotor);

        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'motivo' => 'Fechando pelo gestor.',
            'fim_data' => now()->subHour()->toIso8601String(),
        ])->assertOk();

        $response = $this->getJson("/api/visitas/{$visita->uuid}")->assertOk();
        $response->assertJsonPath('visita.checkout_tipo', 'ADMIN');
        $this->assertCount(1, $response->json('visita.intervencoes'));
        $this->assertSame('CHECKOUT_FORCADO', $response->json('visita.intervencoes.0.acao'));
        $this->assertSame($admin->uuid, $response->json('visita.intervencoes.0.usuario.id'));
    }
}
