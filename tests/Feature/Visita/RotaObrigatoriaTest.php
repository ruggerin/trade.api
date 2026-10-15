<?php

namespace Tests\Feature\Visita;

use App\Enums\Permissao;
use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\LiberacaoVisita;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Iniciar visita só pela agenda e seguir a sequência do dia, por perfil (docs/74).
 */
class RotaObrigatoriaTest extends TestCase
{
    use RefreshDatabase;

    private const AGENDA = Permissao::VISITAS_EXIGIR_AGENDA->value;

    private const SEQUENCIA = Permissao::VISITAS_EXIGIR_SEQUENCIA->value;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 10:00', 'America/Sao_Paulo'));
        $this->empresa = Empresa::factory()->create();
    }

    private function promotor(array $marcas = []): Usuario
    {
        $fabrica = Usuario::factory()->promotor();
        $usuario = ($marcas ? $fabrica->comPerfil($marcas) : $fabrica)->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($usuario);

        return $usuario->fresh();
    }

    private function loja(string $nome = 'Loja'): PontoVenda
    {
        return PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => $nome]);
    }

    private function os(Usuario $promotor, PontoVenda $loja, ?string $horario = null, int $dias = 0): OrdemServico
    {
        $dia = now('America/Sao_Paulo')->addDays($dias);

        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $promotor->id,
            'prazo_inicio' => $dia->copy()->startOfDay(),
            'prazo_fim' => $dia->copy()->endOfDay(),
            'horario_previsto' => $horario,
        ]);
    }

    private function checkin(PontoVenda $loja, ?OrdemServico $os = null, bool $escolhida = false, ?string $liberacao = null)
    {
        return $this->postJson('/api/visitas', array_filter([
            'ponto_venda_uuid' => $loja->uuid,
            'latitude' => $loja->latitude,
            'longitude' => $loja->longitude,
            'ordem_servico_uuid' => $os?->uuid,
            'ordem_servico_escolhida' => $escolhida ?: null,
            'liberacao_uuid' => $liberacao,
        ]));
    }

    // ——— Perfil ———

    public function test_perfil_nao_salva_sequencia_sem_agenda(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/perfis', ['nome' => 'Promotor', 'permissoes' => [self::SEQUENCIA]])
            ->assertStatus(422)->assertJsonValidationErrors('permissoes');
        $this->postJson('/api/perfis', ['nome' => 'Promotor', 'permissoes' => [self::AGENDA, self::SEQUENCIA]])
            ->assertCreated();
    }

    // ——— Só pela agenda ———

    public function test_sem_a_marca_tudo_igual_a_hoje(): void
    {
        $this->promotor();

        $this->checkin($this->loja())->assertCreated();
    }

    public function test_com_a_marca_recusa_visita_sem_compromisso_ou_pela_aba_lojas(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        $loja = $this->loja();
        $os = $this->os($promotor, $loja);

        $this->checkin($loja)->assertStatus(422)->assertJsonPath('message', 'Seu perfil só permite iniciar visita pela agenda.');
        // Pela aba Lojas o app só sugere a OS (sem `escolhida`).
        $this->checkin($loja, $os)->assertStatus(422);
        $this->checkin($loja, $os, escolhida: true)->assertCreated();
    }

    public function test_visita_aberta_e_retomada_mesmo_com_a_marca(): void
    {
        $promotor = $this->promotor();
        $loja = $this->loja();
        $this->checkin($loja)->assertCreated();

        $promotor->perfil()->associate(
            \App\Models\Perfil::factory()->comPermissoes([self::AGENDA])->create(['empresa_id' => $this->empresa->id]),
        )->save();

        $this->checkin($loja)->assertOk()->assertJsonPath('retomada', true);
    }

    public function test_admin_nunca_e_restringido(): void
    {
        $admin = Usuario::factory()->admin()->comPerfil([self::AGENDA, self::SEQUENCIA])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin->fresh());

        $this->checkin($this->loja())->assertCreated();
    }

    // ——— Sequência ———

    public function test_so_a_visita_da_vez_inicia_e_concluir_libera_a_seguinte(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $primeira = $this->os($promotor, $this->loja('Mercado Centro'), '09:00');
        $segundaLoja = $this->loja('Mercado Norte');
        $segunda = $this->os($promotor, $segundaLoja, '11:00');
        // Pendência de ontem não trava a fila de hoje.
        $this->os($promotor, $this->loja('Ontem'), '08:00', dias: -1);

        $this->checkin($segundaLoja, $segunda, escolhida: true)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Antes, atenda Mercado Centro (previsto 09:00).')
            ->assertJsonPath('ordem_servico_da_vez', $primeira->uuid);

        $primeira->update(['status' => StatusOrdemServico::CONCLUIDA]);

        $this->checkin($segundaLoja, $segunda, escolhida: true)->assertCreated();
    }

    public function test_sem_horario_previsto_entra_depois_dos_que_tem(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $semHorario = $this->os($promotor, $this->loja('Sem horário'));
        $lojaComHorario = $this->loja('Com horário');
        $comHorario = $this->os($promotor, $lojaComHorario, '16:00');

        $this->checkin($lojaComHorario, $comHorario, escolhida: true)->assertCreated();
        $this->assertSame(StatusOrdemServico::PENDENTE, $semHorario->fresh()->status);
    }

    public function test_pular_a_vez_exige_motivo_e_libera_a_seguinte(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $fechada = $this->os($promotor, $this->loja('Fechada'), '09:00');
        $proximaLoja = $this->loja('Próxima');
        $proxima = $this->os($promotor, $proximaLoja, '11:00');
        $motivo = MotivoNaoExecucao::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja fechada']);

        $this->postJson("/api/ordens-servico/{$fechada->uuid}/cancelar")
            ->assertStatus(422)->assertJsonValidationErrors('motivo_uuid');

        $this->postJson("/api/ordens-servico/{$fechada->uuid}/cancelar", ['motivo_uuid' => $motivo->uuid])->assertOk();
        $this->assertSame($motivo->id, $fechada->fresh()->motivo_cancelamento_id);

        $this->checkin($proximaLoja, $proxima, escolhida: true)->assertCreated();
    }

    // ——— Exceção autorizada pelo gestor ———

    public function test_gestor_libera_uma_visita_fora_da_agenda_de_uso_unico(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        $loja = $this->loja();
        $gestor = Usuario::factory()->gestor()->comPerfil([Permissao::VISITAS_INTERVIR->value])->create(['empresa_id' => $this->empresa->id]);

        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'email' => $gestor->email, 'senha' => 'errada'])
            ->assertForbidden();

        $liberacao = $this->postJson('/api/visitas/liberacoes', [
            'ponto_venda_uuid' => $loja->uuid, 'email' => $gestor->email, 'senha' => 'senha-teste', 'motivo' => 'Loja nova',
        ])->assertCreated()->assertJsonPath('liberacao.autorizada_por.nome', $gestor->nome)->json('liberacao.id');

        // Vale só na loja dela.
        $this->checkin($this->loja('Outra'), liberacao: $liberacao)->assertStatus(422);

        $visita = $this->checkin($loja, liberacao: $liberacao)->assertCreated()->json('visita.id');
        $registro = LiberacaoVisita::where('uuid', $liberacao)->first();
        $this->assertNotNull($registro->usada_em);
        $this->assertSame($gestor->id, $registro->autorizada_por_id);
        $this->assertSame('Loja nova', $registro->motivo);

        // Uso único: a próxima visita (depois de encerrar esta) precisa de outra autorização.
        \App\Models\Visita::where('uuid', $visita)->update(['status' => 'FINALIZADA']);
        $this->checkin($loja, liberacao: $liberacao)->assertStatus(422);
    }

    public function test_liberacao_vale_tambem_fora_da_sequencia_e_com_codigo(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $this->os($promotor, $this->loja('Primeira'), '09:00');
        $loja = $this->loja('Fora da vez');
        $gestor = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $codigo = \App\Models\AutorizacaoGestor::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $gestor->id, 'codigo' => '123456', 'expira_em' => now()->addMinutes(10),
        ]);

        $liberacao = $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'codigo' => '123456'])
            ->assertCreated()->json('liberacao.id');
        $this->assertNotNull($codigo->fresh()->usado_em);

        $this->checkin($loja, liberacao: $liberacao)->assertCreated();
    }

    public function test_liberacao_so_para_quem_tem_a_marca(): void
    {
        $this->promotor();

        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $this->loja()->uuid, 'codigo' => '123456'])
            ->assertStatus(422);
    }
}
