<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\ObjetivoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/13-AGENDA-MOBILE-E-AUTONOMIA.md §3 — motivo de negócio de uma OrdemServico (ex.
 * "Reposição", "Negociação"), cadastro por empresa, mesmo desenho de TipoVisita mas sem cor.
 */
class ObjetivoVisitaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_objetivo_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/objetivos-visita', ['descricao' => 'Negociação']);

        $response->assertCreated()
            ->assertJsonPath('objetivo_visita.descricao', 'Negociação')
            ->assertJsonPath('objetivo_visita.ativo', true);
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/objetivos-visita', ['descricao' => 'Negociação'])->assertForbidden();
    }

    public function test_lista_aberta_a_qualquer_autenticado(): void
    {
        $empresa = Empresa::factory()->create();
        ObjetivoVisita::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/objetivos-visita')->assertOk()->assertJsonCount(1, 'objetivos_visita');
    }

    public function test_destroy_desativa_em_vez_de_apagar(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $objetivo = ObjetivoVisita::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/objetivos-visita/{$objetivo->uuid}")->assertNoContent();

        $this->assertFalse($objetivo->fresh()->ativo);
    }
}
