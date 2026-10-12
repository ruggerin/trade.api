<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\SolicitacaoAparelho;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/67 §3 (decisão de 12/10/2026): licença do app mobile atribuída pelo ADMIN a qualquer
 * user_type, a seção "Acesso ao app mobile" do cadastro e o "Liberar nova troca".
 */
class Doc67LicencaAtribuidaTest extends TestCase
{
    use RefreshDatabase;

    /** Request novo de verdade — ver Doc67LicenciamentoTest::novaRequisicao. */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->defaultHeaders = [];
    }

    private function loginApp(Usuario $usuario, string $aparelho): TestResponse
    {
        $this->novaRequisicao();

        return $this->withHeaders(['X-Client' => 'mobile'])->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => $aparelho,
            'dispositivo_nome' => "Celular {$aparelho}",
        ]);
    }

    private function comoAdmin(Empresa $empresa): Usuario
    {
        $this->novaRequisicao();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_sem_licenca_nao_entra_no_app_mas_entra_no_admin_web(): void
    {
        $gestor = Usuario::factory()->gestor()->create(['senha_hash' => Hash::make('senha-correta')]);

        $this->loginApp($gestor, 'a')->assertForbidden()->assertJsonPath('codigo', 'SEM_LICENCA');
        $this->assertNull($gestor->dispositivo()->first());

        $this->novaRequisicao();
        $this->withHeaders(['X-Client' => 'admin'])->postJson('/api/auth/login', [
            'email' => $gestor->email, 'senha' => 'senha-correta',
        ])->assertOk();
    }

    public function test_promotor_nasce_com_licenca_e_gestor_sem(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 5, 'limite_usuarios' => null]);
        $this->comoAdmin($empresa);

        $this->postJson('/api/usuarios', ['nome' => 'P', 'email' => 'p@e.com', 'senha' => 'senha12345', 'user_type' => 'PROMOTOR'])
            ->assertCreated()->assertJsonPath('usuario.licenca_mobile', true);
        $this->postJson('/api/usuarios', ['nome' => 'G', 'email' => 'g@e.com', 'senha' => 'senha12345', 'user_type' => 'GESTOR'])
            ->assertCreated()->assertJsonPath('usuario.licenca_mobile', false);
        $this->postJson('/api/usuarios', ['nome' => 'G2', 'email' => 'g2@e.com', 'senha' => 'senha12345', 'user_type' => 'GESTOR', 'licenca_mobile' => true])
            ->assertCreated()->assertJsonPath('usuario.licenca_mobile', true);
    }

    public function test_atribuir_licenca_respeita_o_limite_e_conta_qualquer_tipo(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1, 'limite_usuarios' => null]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $outro = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $this->comoAdmin($empresa);

        $this->putJson("/api/usuarios/{$gestor->uuid}", ['licenca_mobile' => true])->assertOk();
        $this->putJson("/api/usuarios/{$outro->uuid}", ['licenca_mobile' => true])->assertStatus(422);
        // Promotor novo também esbarra: a licença do gestor ocupou a única.
        $this->postJson('/api/usuarios', ['nome' => 'P', 'email' => 'p@e.com', 'senha' => 'senha12345', 'user_type' => 'PROMOTOR'])
            ->assertStatus(422);
        // Mas dá pra criar um promotor sem licença (ex.: vai receber depois).
        $this->postJson('/api/usuarios', ['nome' => 'P', 'email' => 'p@e.com', 'senha' => 'senha12345', 'user_type' => 'PROMOTOR', 'licenca_mobile' => false])
            ->assertCreated();
    }

    public function test_remover_licenca_desvincula_o_aparelho_e_derruba_so_a_sessao_do_app(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->comLicencaMobile()->create([
            'empresa_id' => $empresa->id, 'senha_hash' => Hash::make('senha-correta'),
        ]);
        $tokenApp = $this->loginApp($gestor, 'a')->assertOk()->json('token');
        $this->novaRequisicao();
        $tokenWeb = $this->withHeaders(['X-Client' => 'admin'])->postJson('/api/auth/login', [
            'email' => $gestor->email, 'senha' => 'senha-correta',
        ])->json('token');

        $this->comoAdmin($empresa);
        $this->putJson("/api/usuarios/{$gestor->uuid}", ['licenca_mobile' => false])->assertOk();

        $this->novaRequisicao();
        $this->withToken($tokenApp)->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('motivo', 'SEM_LICENCA');
        $this->novaRequisicao();
        $this->withToken($tokenWeb)->getJson('/api/auth/me')->assertOk();
        $this->assertNull($gestor->dispositivo()->first());
    }

    public function test_virar_promotor_ganha_licenca(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 5]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $this->comoAdmin($empresa);

        $this->putJson("/api/usuarios/{$gestor->uuid}", ['user_type' => 'PROMOTOR'])
            ->assertOk()->assertJsonPath('usuario.licenca_mobile', true);
    }

    public function test_licencas_lista_licenciados_sem_aparelho_e_quem_pode_receber(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 10]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'Ana']);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'nome' => 'Bia']);
        $admin = $this->comoAdmin($empresa);

        $resposta = $this->getJson('/api/empresa/licencas')->assertOk()
            ->assertJsonPath('uso.licencas_usadas', 1)
            ->assertJsonPath('licenciados.0.usuario_uuid', $promotor->uuid)
            ->assertJsonPath('licenciados.0.tem_aparelho', false)
            ->assertJsonPath('com_aparelho', 0);

        $semLicenca = array_column($resposta->json('sem_licenca'), 'usuario_uuid');
        $this->assertContains($gestor->uuid, $semLicenca);
        $this->assertContains($admin->uuid, $semLicenca);
        $this->assertNotContains($promotor->uuid, $semLicenca);
    }

    public function test_acesso_app_mostra_aparelho_janela_de_troca_e_pedido(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 10]);
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c')->assertStatus(423);

        $this->comoAdmin($empresa);
        $this->getJson("/api/usuarios/{$promotor->uuid}/acesso-app")->assertOk()
            ->assertJsonPath('licenca_mobile', true)
            ->assertJsonPath('uso.licencas_usadas', 1)
            ->assertJsonPath('dispositivo.nome', 'Celular b')
            ->assertJsonPath('dispositivo.origem', Dispositivo::ORIGEM_TROCA_PROPRIA)
            ->assertJsonPath('trocas_aparelho_30d', 1)
            ->assertJsonPath('solicitacao.aparelho', 'Celular c')
            ->assertJsonPath('troca_liberada', false)
            ->assertJsonPath('proxima_troca_propria_em', fn ($v) => $v !== null);
    }

    public function test_gestor_com_usuarios_gerenciar_decide_pedido_pelo_cadastro(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c');
        $solicitacao = SolicitacaoAparelho::withoutGlobalScopes()->sole();

        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->gestor()->comPerfil(['usuarios.gerenciar'])->create(['empresa_id' => $empresa->id]));
        $this->postJson("/api/solicitacoes-aparelho/{$solicitacao->uuid}/recusar")->assertNoContent();
    }

    public function test_liberar_nova_troca_deixa_o_proximo_aparelho_entrar_uma_vez(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotor, 'a');
        $tokenB = $this->loginApp($promotor, 'b')->json('token');

        $this->comoAdmin($empresa);
        $this->postJson("/api/usuarios/{$promotor->uuid}/liberar-troca-aparelho")->assertNoContent();

        // O atual segue funcionando até o novo entrar.
        $this->novaRequisicao();
        $this->withToken($tokenB)->getJson('/api/auth/me')->assertOk();

        $this->loginApp($promotor, 'c')->assertOk();
        $this->assertSame(Dispositivo::ORIGEM_LIBERADO_ADMIN, $promotor->dispositivo()->first()->origem);
        $this->assertNull($promotor->fresh()->troca_aparelho_liberada_em);

        // Liberação consumida: a próxima volta pra regra normal.
        $this->loginApp($promotor, 'd')->assertStatus(423);
    }

    /**
     * Regressão achada no teste ponta a ponta: cada login/refresh troca o token, então olhar só o
     * token atual mostrava "nunca" e "0 usando agora" pra quem tinha acabado de entrar no app.
     */
    public function test_ultimo_uso_conta_o_login_e_tokens_anteriores_do_aparelho(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotor, 'a')->assertOk();

        $this->comoAdmin($empresa);
        $this->getJson('/api/empresa/licencas')->assertOk()
            ->assertJsonPath('usando_agora', 1)
            ->assertJsonPath('licenciados.0.online', true)
            ->assertJsonPath('licenciados.0.ultimo_uso_em', fn ($v) => $v !== null);
        $this->getJson("/api/usuarios/{$promotor->uuid}/acesso-app")->assertOk()
            ->assertJsonPath('dispositivo.online', true);

        $this->travel(2)->hours();
        $this->getJson('/api/empresa/licencas')->assertOk()
            ->assertJsonPath('usando_agora', 0)
            ->assertJsonPath('licenciados.0.online', false)
            ->assertJsonPath('licenciados.0.ultimo_uso_em', fn ($v) => $v !== null);
    }

    public function test_liberar_troca_exige_licenca(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $this->comoAdmin($empresa);

        $this->postJson("/api/usuarios/{$gestor->uuid}/liberar-troca-aparelho")->assertStatus(422);
    }
}
