<?php

namespace Tests\Feature\PontoVenda;

use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dias da semana com atendimento (destaque no check-in mobile) — ver
 * docs/45-CHECKIN-ATENDIMENTO-SEMANAL.md e PontoVendaController::agendaSemanal.
 */
class AgendaSemanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_traz_os_dias_das_agendas_semanais_ativas(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        AgendaVisita::factory()->semanal(1)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        AgendaVisita::factory()->semanal(5)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/pontos-venda/{$pdv->uuid}/agenda-semanal")
            ->assertOk()
            ->assertJsonPath('dias_atendimento', [1, 5]);
    }

    public function test_ignora_data_unica_e_agenda_inativa(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        AgendaVisita::factory()->semanal(1)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        AgendaVisita::factory()->semanal(2)->inativa()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        AgendaVisita::factory()->dataUnica(now()->addDay()->toDateString())->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->getJson("/api/pontos-venda/{$pdv->uuid}/agenda-semanal")
            ->assertOk()
            ->assertJsonPath('dias_atendimento', [1]);
    }

    public function test_dedupe_quando_mais_de_um_promotor_atende_no_mesmo_dia(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        AgendaVisita::factory()->semanal(3)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        AgendaVisita::factory()->semanal(3)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->getJson("/api/pontos-venda/{$pdv->uuid}/agenda-semanal")
            ->assertOk()
            ->assertJsonPath('dias_atendimento', [3]);
    }

    public function test_sem_agenda_devolve_lista_vazia(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->getJson("/api/pontos-venda/{$pdv->uuid}/agenda-semanal")
            ->assertOk()
            ->assertJsonPath('dias_atendimento', []);
    }
}
