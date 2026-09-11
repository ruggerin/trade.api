<?php

namespace Tests\Feature;

use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/10-AGENDA-VISITA.md §3.4 — geração automática de OS a partir da agenda de visita
 * (`php artisan ordens-servico:gerar-por-agenda`, agendado diário em routes/console.php).
 */
class GerarOrdensServicoPorAgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_os_pendente_para_agenda_semanal_que_bate_com_hoje(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoVisita::factory()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->semanal(now()->dayOfWeek)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'tipo_visita_id' => $tipo->id, 'prioridade' => 'ALTA', 'horario_previsto' => '09:00',
        ]);

        $this->artisan('ordens-servico:gerar-por-agenda')->assertSuccessful();

        $this->assertDatabaseHas('ordens_servico', [
            'agenda_visita_id' => $agenda->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'origem' => 'AGENDA', 'status' => 'PENDENTE', 'tipo_visita_id' => $tipo->id, 'prioridade' => 'ALTA',
        ]);
    }

    public function test_ignora_agenda_semanal_que_nao_bate_com_hoje(): void
    {
        $empresa = Empresa::factory()->create();
        $outroDia = (now()->dayOfWeek + 1) % 7;
        AgendaVisita::factory()->semanal($outroDia)->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->count());
    }

    public function test_cria_os_para_agenda_de_data_unica_de_hoje_e_auto_desativa(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->dataUnica(now()->toDateString())->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertDatabaseHas('ordens_servico', [
            'agenda_visita_id' => $agenda->id, 'origem' => 'AGENDA', 'status' => 'PENDENTE',
        ]);
        $this->assertFalse($agenda->fresh()->ativo);
    }

    public function test_ignora_agenda_de_data_unica_de_outro_dia(): void
    {
        $empresa = Empresa::factory()->create();
        AgendaVisita::factory()->dataUnica(now()->addWeek()->toDateString())->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->count());
    }

    public function test_ignora_agenda_inativa(): void
    {
        $empresa = Empresa::factory()->create();
        AgendaVisita::factory()->semanal(now()->dayOfWeek)->inativa()->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->count());
    }

    public function test_nao_duplica_quando_ja_existe_os_criada_hoje_para_a_agenda(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->semanal(now()->dayOfWeek)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'agenda_visita_id' => $agenda->id,
            'origem' => 'AGENDA', 'prazo_inicio' => now(), 'prazo_fim' => now()->endOfDay(),
        ]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertSame(1, OrdemServico::withoutGlobalScopes()->where('agenda_visita_id', $agenda->id)->count());
    }

    public function test_cria_de_novo_na_proxima_semana_mesmo_com_uma_os_concluida_hoje_de_ontem(): void
    {
        // Ciclo semanal: uma OS de uma semana passada concluída não deve bloquear a de hoje —
        // diferente da campanha (que olha frequencia_dias), aqui o dedupe é só "já criada hoje".
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $agenda = AgendaVisita::factory()->semanal(now()->dayOfWeek)->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
        OrdemServico::factory()->concluida()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'agenda_visita_id' => $agenda->id,
            'origem' => 'AGENDA', 'prazo_inicio' => now()->subWeek(), 'prazo_fim' => now()->subWeek()->endOfDay(),
        ]);

        $this->artisan('ordens-servico:gerar-por-agenda');

        $this->assertSame(2, OrdemServico::withoutGlobalScopes()->where('agenda_visita_id', $agenda->id)->count());
    }
}
