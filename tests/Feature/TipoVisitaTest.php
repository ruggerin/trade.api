<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/10-AGENDA-VISITA.md §3.1 — tag colorida (descrição + hex) usada pra classificar uma
 * OrdemServico, manual ou gerada por AgendaVisita. Mesma permissão de OS (ordens_servico.gerenciar).
 */
class TipoVisitaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_tipo_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-visita', [
            'descricao' => 'Reposição',
            'cor' => '#2563EB',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_visita.descricao', 'Reposição')
            ->assertJsonPath('tipo_visita.cor', '#2563EB')
            ->assertJsonPath('tipo_visita.ativo', true);
    }

    public function test_rejeita_cor_fora_do_formato_hex(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-visita', ['descricao' => 'Reposição', 'cor' => 'azul'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cor');
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/tipos-visita', ['descricao' => 'Reposição', 'cor' => '#2563EB'])
            ->assertForbidden();
    }

    public function test_lista_aberta_a_qualquer_autenticado(): void
    {
        $empresa = Empresa::factory()->create();
        TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/tipos-visita')->assertOk()->assertJsonCount(1, 'tipos_visita');
    }

    public function test_destroy_desativa_em_vez_de_apagar(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/tipos-visita/{$tipo->uuid}")->assertNoContent();

        $this->assertFalse($tipo->fresh()->ativo);
    }
}
