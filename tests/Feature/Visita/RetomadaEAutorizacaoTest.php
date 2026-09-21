<?php

namespace Tests\Feature\Visita;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Check-in que retoma a visita aberta da mesma loja e a saída de segurança "cancelar com autorização
 * do gestor (senha)" — visita travada que o promotor não consegue cancelar sozinho.
 */
class RetomadaEAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private PontoVenda $pdv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->promotor);
    }

    private function checkin(?string $osUuid = null, ?PontoVenda $pdv = null): \Illuminate\Testing\TestResponse
    {
        $pdv ??= $this->pdv;

        return $this->postJson('/api/visitas', array_filter([
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $osUuid,
        ]));
    }

    // ---- retomada ----

    public function test_segundo_checkin_na_mesma_loja_retoma_a_visita_aberta(): void
    {
        $primeira = $this->checkin()->assertCreated()->json('visita.id');

        $this->checkin()->assertOk()
            ->assertJsonPath('visita.id', $primeira)
            ->assertJsonPath('retomada', true);

        $this->assertSame(1, Visita::withoutGlobalScopes()->where('usuario_id', $this->promotor->id)->count());
    }

    public function test_loja_diferente_abre_visita_nova(): void
    {
        $this->checkin()->assertCreated();
        $outra = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);

        $this->checkin(null, $outra)->assertCreated();

        $this->assertSame(2, Visita::withoutGlobalScopes()->where('usuario_id', $this->promotor->id)->count());
    }

    public function test_visita_finalizada_nao_e_retomada(): void
    {
        $id = $this->checkin()->assertCreated()->json('visita.id');
        $this->patchJson("/api/visitas/{$id}/checkout", ['latitude' => $this->pdv->latitude, 'longitude' => $this->pdv->longitude])->assertOk();

        $this->checkin()->assertCreated();
    }

    public function test_retomada_vincula_a_os_quando_a_visita_aberta_nao_tinha(): void
    {
        $id = $this->checkin()->assertCreated()->json('visita.id');
        $os = OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
        ]);

        $this->checkin($os->uuid)->assertOk()
            ->assertJsonPath('visita.id', $id)
            ->assertJsonPath('visita.ordem_servico.id', $os->uuid);

        $this->assertSame('EM_ANDAMENTO', $os->refresh()->status->value);
    }

    public function test_promotor_de_outra_pessoa_nao_retoma_visita_alheia(): void
    {
        $this->checkin()->assertCreated();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->checkin()->assertCreated();
    }

    // ---- cancelamento autorizado ----

    private function visitaAberta(): string
    {
        return $this->checkin()->assertCreated()->json('visita.id');
    }

    private function supervisor(string $tipo = 'admin', string $senha = 'senha-do-gestor'): Usuario
    {
        return Usuario::factory()->{$tipo}()->create([
            'empresa_id' => $this->empresa->id, 'nome' => 'Gestora Bia', 'email' => 'bia@empresa.test', 'senha_hash' => Hash::make($senha),
        ]);
    }

    public function test_admin_autoriza_o_cancelamento_com_email_e_senha(): void
    {
        $id = $this->visitaAberta();
        $this->supervisor();

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])
            ->assertOk()->assertJsonPath('visita.status', 'CANCELADA');

        // Auditoria: a autoria é do gestor que autorizou, não do promotor.
        $intervencao = \App\Models\VisitaIntervencao::first();
        $this->assertSame('Gestora Bia', $intervencao->usuario->nome);
        $this->assertStringContainsString('Ana', $intervencao->descricao);
    }

    public function test_cancelar_libera_a_ordem_de_servico(): void
    {
        $os = OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
        ]);
        $id = $this->checkin($os->uuid)->assertCreated()->json('visita.id');
        $this->supervisor();

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])->assertOk();

        $os->refresh();
        $this->assertSame('PENDENTE', $os->status->value);
        $this->assertNull($os->visita_id);
    }

    public function test_senha_errada_e_negada_sem_dizer_o_que_falhou(): void
    {
        $id = $this->visitaAberta();
        $this->supervisor();

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'errada'])->assertForbidden();
        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'naoexiste@empresa.test', 'senha' => 'x'])->assertForbidden();

        $this->assertSame('ABERTA', Visita::withoutGlobalScopes()->find(Visita::withoutGlobalScopes()->max('id'))->status->value);
    }

    public function test_gestor_sem_permissao_de_intervir_e_negado_e_com_permissao_passa(): void
    {
        $id = $this->visitaAberta();
        $gestor = $this->supervisor('gestor');

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])->assertForbidden();

        $perfil = Perfil::factory()->comPermissoes([Permissao::VISITAS_INTERVIR->value])->create(['empresa_id' => $this->empresa->id]);
        $gestor->update(['perfil_id' => $perfil->id]);

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])->assertOk();
    }

    public function test_promotor_colega_nao_serve_de_autorizador(): void
    {
        $id = $this->visitaAberta();
        Usuario::factory()->promotor()->create([
            'empresa_id' => $this->empresa->id, 'email' => 'colega@empresa.test', 'senha_hash' => Hash::make('abc12345'),
        ]);

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'colega@empresa.test', 'senha' => 'abc12345'])->assertForbidden();
    }

    public function test_admin_de_outra_empresa_nao_autoriza(): void
    {
        $id = $this->visitaAberta();
        Usuario::factory()->admin()->create([
            'empresa_id' => Empresa::factory()->create()->id, 'email' => 'outro@x.test', 'senha_hash' => Hash::make('senha-do-gestor'),
        ]);

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'outro@x.test', 'senha' => 'senha-do-gestor'])->assertForbidden();
    }

    public function test_limita_tentativas_de_senha(): void
    {
        RateLimiter::clear('cancelar-autorizado:'.$this->promotor->id);
        $id = $this->visitaAberta();
        $this->supervisor();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'errada'])->assertForbidden();
        }

        // Depois de 5 erros, até a senha certa fica bloqueada por um tempo.
        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])
            ->assertStatus(429);
    }

    public function test_promotor_nao_cancela_visita_de_outro_promotor_mesmo_com_senha_certa(): void
    {
        $id = $this->visitaAberta();
        $this->supervisor();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'])->assertForbidden();
    }

    public function test_cancelar_de_novo_e_idempotente(): void
    {
        $id = $this->visitaAberta();
        $this->supervisor();
        $corpo = ['email' => 'bia@empresa.test', 'senha' => 'senha-do-gestor'];

        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", $corpo)->assertOk();
        $this->postJson("/api/visitas/{$id}/cancelar-autorizado", $corpo)->assertOk()->assertJsonPath('visita.status', 'CANCELADA');
    }
}
