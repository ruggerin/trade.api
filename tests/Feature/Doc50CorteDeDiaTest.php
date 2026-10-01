<?php

namespace Tests\Feature;

use App\Enums\RecorrenciaAgendaVisita;
use App\Enums\StatusOrdemServico;
use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\Fuso;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/50 §4.2/§4.3 — "hoje", atraso, geração de OS e filtros de período no fuso da empresa/loja,
 * com o servidor em UTC. Cenário base: empresa em Manaus (UTC-4).
 */
class Doc50CorteDeDiaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create(['fuso' => 'America/Manaus']);
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** OS do dia local (Manaus) inteiro, como o gerador grava. */
    private function osDoDia(string $dia, string $horario, array $extra = []): OrdemServico
    {
        [$inicio, $fim] = Fuso::intervaloDoDia($dia, 'America/Manaus');

        return OrdemServico::factory()->create(array_merge([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $this->pdv->id,
            'usuario_id' => $this->promotor->id,
            'status' => StatusOrdemServico::PENDENTE,
            'horario_previsto' => $horario,
            'prazo_inicio' => $inicio,
            'prazo_fim' => $fim,
        ], $extra));
    }

    private function statusNaOperacao(): ?string
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        return collect($this->getJson('/api/operacao-do-dia')->assertOk()->json('equipe'))
            ->firstWhere('usuario.id', $this->promotor->uuid)['status'] ?? null;
    }

    public function test_atraso_e_medido_no_horario_local_da_loja(): void
    {
        $this->osDoDia('2026-10-01', '08:00');

        // 11:00 UTC = 07:00 em Manaus: ainda não chegou a hora.
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00', 'UTC'));
        $this->assertSame('DESLOCAMENTO', $this->statusNaOperacao());

        // 12:31 UTC = 08:31 em Manaus: passou das 08:00 + 30 min de tolerância.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:31:00', 'UTC'));
        $this->assertSame('ATRASADO', $this->statusNaOperacao());
    }

    public function test_loja_em_outro_fuso_usa_o_horario_dela(): void
    {
        $this->pdv->update(['fuso' => 'America/Rio_Branco']); // UTC-5
        $this->osDoDia('2026-10-01', '08:00');

        // 12:40 UTC = 08:40 em Manaus, mas 07:40 na loja (Rio Branco): não está atrasado.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:40:00', 'UTC'));
        $this->assertSame('DESLOCAMENTO', $this->statusNaOperacao());
    }

    public function test_hoje_do_painel_e_o_dia_de_manaus_mesmo_depois_da_meia_noite_utc(): void
    {
        $this->osDoDia('2026-10-01', '08:00', ['status' => StatusOrdemServico::CONCLUIDA]);

        // 02:00 UTC de 02/10 = 22:00 de 01/10 em Manaus.
        Carbon::setTestNow(Carbon::parse('2026-10-02 02:00:00', 'UTC'));
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $resposta = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame('2026-10-01', $resposta->json('data'));
        $this->assertSame('ENCERRADO', collect($resposta->json('equipe'))->firstWhere('usuario.id', $this->promotor->uuid)['status']);
    }

    public function test_gerador_por_agenda_usa_o_dia_local_da_empresa(): void
    {
        // 01/10/2026 é quinta (dia_semana 4). Às 02:00 UTC de 02/10 ainda é quinta em Manaus.
        AgendaVisita::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
            'recorrencia' => RecorrenciaAgendaVisita::SEMANAL, 'dia_semana' => 4, 'horario_previsto' => '08:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-02 02:00:00', 'UTC'));

        $this->artisan('ordens-servico:gerar-por-agenda')->assertSuccessful();

        $os = OrdemServico::withoutGlobalScopes()->where('usuario_id', $this->promotor->id)->sole();
        $this->assertSame('2026-10-01 04:00:00', $os->prazo_inicio->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 03:59:59', $os->prazo_fim->utc()->format('Y-m-d H:i:s'));

        // Rodar de novo no mesmo dia local não duplica.
        $this->artisan('ordens-servico:gerar-por-agenda')->assertSuccessful();
        $this->assertSame(1, OrdemServico::withoutGlobalScopes()->where('usuario_id', $this->promotor->id)->count());
    }

    public function test_filtro_de_periodo_corta_o_dia_em_manaus(): void
    {
        $base = ['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id];
        $noite = Visita::factory()->create($base + ['inicio_data' => '2026-10-02 02:00:00']);   // 22:00 de 01/10 em Manaus
        $vespera = Visita::factory()->create($base + ['inicio_data' => '2026-10-01 03:00:00']); // 23:00 de 30/09 em Manaus

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $ids = collect($this->getJson('/api/visitas?data_inicio=2026-10-01&data_fim=2026-10-01')->assertOk()->json('visitas'))->pluck('id');

        $this->assertTrue($ids->contains($noite->uuid));
        $this->assertFalse($ids->contains($vespera->uuid));
    }
}
