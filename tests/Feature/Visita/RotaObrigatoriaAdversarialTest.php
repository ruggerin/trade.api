<?php

namespace Tests\Feature\Visita;

use App\Enums\Permissao;
use App\Models\AutorizacaoGestor;
use App\Models\Empresa;
use App\Models\LiberacaoVisita;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tenta furar a rota obrigatória (docs/74) do jeito que um app modificado, um colega ou uma
 * empresa vizinha tentaria. Complementa RotaObrigatoriaTest, que cobre o caminho feliz.
 */
class RotaObrigatoriaAdversarialTest extends TestCase
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

    private function promotor(array $marcas = [self::AGENDA], bool $autenticar = true): Usuario
    {
        $fabrica = Usuario::factory()->promotor();
        $usuario = ($marcas ? $fabrica->comPerfil($marcas) : $fabrica)->create(['empresa_id' => $this->empresa->id]);
        if ($autenticar) {
            Sanctum::actingAs($usuario);
        }

        return $usuario->fresh();
    }

    private function loja(string $nome = 'Loja', ?Empresa $empresa = null): PontoVenda
    {
        return PontoVenda::factory()->create(['empresa_id' => ($empresa ?? $this->empresa)->id, 'fantasia' => $nome]);
    }

    private function os(Usuario $promotor, PontoVenda $loja, ?string $horario = null, int $dias = 0, ?int $usuarioId = -1): OrdemServico
    {
        $dia = now('America/Sao_Paulo')->addDays($dias);

        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $usuarioId === -1 ? $promotor->id : $usuarioId,
            'prazo_inicio' => $dia->copy()->startOfDay(),
            'prazo_fim' => $dia->copy()->endOfDay(),
            'horario_previsto' => $horario,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function checkin(PontoVenda $loja, ?OrdemServico $os = null, bool $escolhida = false, ?string $liberacao = null, array $extra = [])
    {
        return $this->postJson('/api/visitas', array_filter([
            'ponto_venda_uuid' => $loja->uuid,
            'latitude' => $loja->latitude,
            'longitude' => $loja->longitude,
            'ordem_servico_uuid' => $os?->uuid,
            'ordem_servico_escolhida' => $escolhida ?: null,
            'liberacao_uuid' => $liberacao,
        ] + $extra));
    }

    private function liberacao(Usuario $promotor, PontoVenda $loja, array $extra = []): LiberacaoVisita
    {
        $gestor = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);

        return LiberacaoVisita::create($extra + [
            'empresa_id' => $this->empresa->id,
            'usuario_id' => $promotor->id,
            'ponto_venda_id' => $loja->id,
            'autorizada_por_id' => $gestor->id,
            'expira_em' => now()->addHours(12),
        ]);
    }

    // ——— Sequência: tentativas de furar ———

    public function test_inicio_em_de_ontem_nao_burla_a_sequencia(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $this->os($promotor, $this->loja('A'), '09:00');
        $lojaB = $this->loja('B');
        $osB = $this->os($promotor, $lojaB, '11:00');

        // Um app modificado manda a hora do campo de ontem pra "mudar o dia" da fila.
        $this->checkin($lojaB, $osB, escolhida: true, extra: ['inicio_em' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);
    }

    public function test_compromisso_de_outro_promotor_nao_trava_a_fila_deste(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $outro = $this->promotor([], autenticar: false);
        $this->os($outro, $this->loja('Do outro'), '07:00');
        $minhaLoja = $this->loja('Minha');
        $minha = $this->os($promotor, $minhaLoja, '11:00');

        $this->checkin($minhaLoja, $minha, escolhida: true)->assertCreated();
    }

    public function test_pendencia_de_ontem_nao_trava_o_dia_de_hoje(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $this->os($promotor, $this->loja('Esquecida'), '09:00', dias: -1);
        $lojaHoje = $this->loja('Hoje');
        $hoje = $this->os($promotor, $lojaHoje, '11:00');

        $this->checkin($lojaHoje, $hoje, escolhida: true)->assertCreated();
    }

    public function test_os_de_outra_empresa_nunca_entra_na_regra(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        $outraEmpresa = Empresa::factory()->create();
        $lojaAlheia = $this->loja('Alheia', $outraEmpresa);
        $osAlheia = OrdemServico::factory()->create([
            'empresa_id' => $outraEmpresa->id, 'ponto_venda_id' => $lojaAlheia->id, 'usuario_id' => null,
            'prazo_inicio' => now()->startOfDay(), 'prazo_fim' => now()->endOfDay(),
        ]);

        // Uuid de OS de outra empresa: a validação do check-in recusa antes de qualquer regra da rota.
        $this->checkin($lojaAlheia, $osAlheia, escolhida: true)->assertStatus(422);
    }

    // ——— Liberação do gestor: abuso ———

    public function test_liberacao_de_outro_promotor_nao_vale(): void
    {
        $dono = $this->promotor([self::AGENDA], autenticar: false);
        $intruso = $this->promotor([self::AGENDA]);
        $loja = $this->loja();
        $liberacao = $this->liberacao($dono, $loja);

        $this->checkin($loja, liberacao: $liberacao->uuid)->assertStatus(422);
        $this->assertNull($liberacao->fresh()->usada_em);
    }

    public function test_liberacao_expirada_nao_vale(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        $loja = $this->loja();
        $liberacao = $this->liberacao($promotor, $loja, ['expira_em' => now()->subMinute()]);

        $this->checkin($loja, liberacao: $liberacao->uuid)->assertStatus(422);
    }

    public function test_liberacao_inventada_nao_vale(): void
    {
        $this->promotor([self::AGENDA]);

        $this->checkin($this->loja(), liberacao: '00000000-0000-4000-8000-000000000000')->assertStatus(422);
    }

    public function test_liberacao_de_outra_empresa_nao_vale(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        $loja = $this->loja();
        $outraEmpresa = Empresa::factory()->create();
        $estrangeira = LiberacaoVisita::withoutGlobalScopes()->create([
            'empresa_id' => $outraEmpresa->id,
            'usuario_id' => $promotor->id,
            'ponto_venda_id' => $loja->id,
            'autorizada_por_id' => Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id])->id,
            'expira_em' => now()->addHours(2),
        ]);

        $this->checkin($loja, liberacao: $estrangeira->uuid)->assertStatus(422);
    }

    public function test_codigo_do_gestor_nao_pode_ser_reusado(): void
    {
        $this->promotor([self::AGENDA]);
        $gestor = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        AutorizacaoGestor::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $gestor->id, 'codigo' => '654321', 'expira_em' => now()->addMinutes(10),
        ]);
        $loja = $this->loja();

        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'codigo' => '654321'])->assertCreated();
        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'codigo' => '654321'])->assertForbidden();
    }

    public function test_codigo_de_outra_empresa_nao_autoriza(): void
    {
        $this->promotor([self::AGENDA]);
        $outraEmpresa = Empresa::factory()->create();
        $gestorAlheio = Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]);
        AutorizacaoGestor::withoutGlobalScopes()->create([
            'empresa_id' => $outraEmpresa->id, 'usuario_id' => $gestorAlheio->id, 'codigo' => '111222', 'expira_em' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $this->loja()->uuid, 'codigo' => '111222'])
            ->assertForbidden();
    }

    public function test_codigo_expirado_nao_autoriza(): void
    {
        $this->promotor([self::AGENDA]);
        $gestor = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        AutorizacaoGestor::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $gestor->id, 'codigo' => '999000', 'expira_em' => now()->subMinute(),
        ]);

        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $this->loja()->uuid, 'codigo' => '999000'])
            ->assertForbidden();
    }

    public function test_forca_bruta_de_codigo_e_bloqueada(): void
    {
        $promotor = $this->promotor([self::AGENDA]);
        RateLimiter::clear('liberar-visita:'.$promotor->id);
        $loja = $this->loja();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'codigo' => '000000'])->assertForbidden();
        }
        $this->postJson('/api/visitas/liberacoes', ['ponto_venda_uuid' => $loja->uuid, 'codigo' => '000000'])->assertStatus(429);
    }

    public function test_corpo_malformado_da_liberacao_nao_gera_erro_500(): void
    {
        $this->promotor([self::AGENDA]);

        foreach ([
            [],
            ['ponto_venda_uuid' => 'nao-e-uuid', 'codigo' => '123456'],
            ['ponto_venda_uuid' => '00000000-0000-4000-8000-000000000000', 'codigo' => '12'],
            ['ponto_venda_uuid' => ['x'], 'codigo' => ['y']],
            ['ponto_venda_uuid' => $this->loja()->uuid, 'email' => 'x@y.com'],
        ] as $corpo) {
            $this->assertLessThan(500, $this->postJson('/api/visitas/liberacoes', $corpo)->status(), json_encode($corpo));
        }
    }

    // ——— Perfil e cancelamento ———

    public function test_atualizar_perfil_nao_deixa_ficar_so_com_a_sequencia(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $perfil = Perfil::factory()->comPermissoes([self::AGENDA, self::SEQUENCIA])->create(['empresa_id' => $this->empresa->id]);

        $this->putJson("/api/perfis/{$perfil->uuid}", ['nome' => 'Promotor', 'permissoes' => [self::SEQUENCIA]])
            ->assertStatus(422)->assertJsonValidationErrors('permissoes');
        $this->assertContains(self::AGENDA, $perfil->fresh()->permissoes);
    }

    public function test_motivo_de_outra_empresa_ou_inativo_nao_serve_pra_pular_a_vez(): void
    {
        $promotor = $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $os = $this->os($promotor, $this->loja(), '09:00');
        $outraEmpresa = Empresa::factory()->create();
        $alheio = MotivoNaoExecucao::withoutGlobalScopes()->create(['empresa_id' => $outraEmpresa->id, 'descricao' => 'Alheio']);
        $inativo = MotivoNaoExecucao::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Antigo', 'ativo' => false]);

        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar", ['motivo_uuid' => $alheio->uuid])
            ->assertStatus(422)->assertJsonValidationErrors('motivo_uuid');
        $this->postJson("/api/ordens-servico/{$os->uuid}/cancelar", ['motivo_uuid' => $inativo->uuid])
            ->assertStatus(422)->assertJsonValidationErrors('motivo_uuid');
        $this->assertNotSame('CANCELADA', $os->fresh()->status->value);
    }

    public function test_cancelar_compromisso_de_outro_promotor_e_recusado(): void
    {
        $this->promotor([self::AGENDA, self::SEQUENCIA]);
        $outro = $this->promotor([], autenticar: false);
        $osAlheia = $this->os($outro, $this->loja('Do outro'), '09:00');
        $motivo = MotivoNaoExecucao::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Fechada']);

        $resposta = $this->postJson("/api/ordens-servico/{$osAlheia->uuid}/cancelar", ['motivo_uuid' => $motivo->uuid]);

        $this->assertContains($resposta->status(), [403, 404], 'status: '.$resposta->status());
        $this->assertNotSame('CANCELADA', $osAlheia->fresh()->status->value);
    }
}
