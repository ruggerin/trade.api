<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\PersonalAccessToken;
use App\Models\Usuario;
use App\Support\SessaoApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/67-LICENCIAMENTO-E-SESSAO-UNICA.md, Fase 1 — cada teste cobre uma brecha do §2 (B1, B2,
 * B4, B7, B8) ou um critério de aceite do §3.3/§4.6.
 *
 * novaRequisicao() entre requests: o guard do Sanctum memoiza o usuário dentro da mesma execução
 * de teste (ver LoginTest::test_promotor_logando_num_aparelho_novo_derruba_a_sessao_do_aparelho_antigo).
 */
class Doc67LicenciamentoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simula o início de um request real: guards novos e o guard padrão de volta pro `web`. Só
     * forgetGuards() não basta quando o token muda — o padrão fica `sanctum` (o auth:sanctum da
     * rota anterior chamou shouldUse), e aí o scope de tenant do Usuario (BelongsToEmpresa chama
     * auth()->user()) consulta o próprio guard que está resolvendo o token: recursão infinita.
     * Em produção não acontece (cada request é um processo novo, começando no `web`).
     */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function loginApp(Usuario $usuario, string $aparelho): string
    {
        $this->novaRequisicao();

        return $this->withHeader('X-Client', 'mobile')->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => $aparelho,
        ])->assertOk()->json('token');
    }

    private function loginWeb(Usuario $usuario): string
    {
        $this->novaRequisicao();

        return $this->withHeader('X-Client', 'admin')->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
        ])->assertOk()->json('token');
    }

    private function me(string $token): \Illuminate\Testing\TestResponse
    {
        $this->novaRequisicao();

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    // --- B1: trava de aparelho pra qualquer user_type no app -------------------------------

    public function test_gestor_logando_no_app_em_outro_aparelho_derruba_a_sessao_do_primeiro(): void
    {
        $gestor = Usuario::factory()->gestor()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        $tokenA = $this->loginApp($gestor, 'celular-a');
        $this->loginApp($gestor, 'celular-b');

        $this->me($tokenA)->assertStatus(401);
        $this->assertDatabaseHas('dispositivos', ['usuario_id' => $gestor->id, 'identificador_hash' => hash('sha256', 'celular-b'), 'desvinculado_em' => null]);
    }

    public function test_admin_no_app_nao_derruba_a_sessao_do_admin_web(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        $tokenWeb = $this->loginWeb($admin);
        $this->loginApp($admin, 'celular-a');
        $this->loginApp($admin, 'celular-b');

        $this->me($tokenWeb)->assertOk();
    }

    public function test_admin_web_em_dois_navegadores_segue_funcionando(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        $primeiro = $this->loginWeb($admin);
        $segundo = $this->loginWeb($admin);

        $this->me($primeiro)->assertOk();
        $this->me($segundo)->assertOk();
    }

    public function test_login_pelo_app_sem_identificador_do_aparelho_e_negado_pra_qualquer_tipo(): void
    {
        $gestor = Usuario::factory()->gestor()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        $this->withHeader('X-Client', 'mobile')->postJson('/api/auth/login', [
            'email' => $gestor->email,
            'senha' => 'senha-correta',
        ])->assertStatus(422)->assertJsonValidationErrors('dispositivo_identificador');
    }

    public function test_token_marca_o_canal_e_so_o_mobile_expira(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        $this->loginWeb($admin);
        $this->loginApp($admin, 'celular-a');

        $web = PersonalAccessToken::where('canal', 'web')->sole();
        $mobile = PersonalAccessToken::where('canal', 'mobile')->sole();
        $this->assertNull($web->expires_at);
        $this->assertTrue($mobile->expires_at->between(now()->addDays(6), now()->addDays(8)));
    }

    // --- Canal: token web não passa nas rotas do app ---------------------------------------

    public function test_token_web_em_rota_exclusiva_do_app_retorna_403(): void
    {
        $gestor = Usuario::factory()->gestor()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);
        $tokenWeb = $this->loginWeb($gestor);

        $this->novaRequisicao();
        $this->withToken($tokenWeb)->postJson('/api/visitas', [])->assertForbidden();
        $this->novaRequisicao();
        $this->withToken($tokenWeb)->patchJson('/api/localizacao', [])->assertForbidden();
    }

    public function test_token_mobile_passa_pela_trava_de_canal(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $token = $this->loginApp($promotor, 'celular-a');

        $this->novaRequisicao();
        // Passa pelo middleware e cai na validação do controller.
        $this->withToken($token)->postJson('/api/visitas', [])->assertStatus(422);
    }

    // --- B4: validade e rotação do token mobile --------------------------------------------

    public function test_token_mobile_vencido_retorna_401(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $token = $this->loginApp($promotor, 'celular-a');

        $this->travel(SessaoApp::VALIDADE_DIAS + 1)->days();

        $this->me($token)->assertStatus(401);
    }

    public function test_refresh_emite_token_novo_e_o_antigo_vale_so_pela_tolerancia(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $antigo = $this->loginApp($promotor, 'celular-a');

        $this->novaRequisicao();
        $novo = $this->withToken($antigo)->postJson('/api/auth/refresh')->assertOk()->json('token');
        $this->assertNotSame($antigo, $novo);

        // Requisição em voo com o antigo, logo depois do refresh: passa.
        $this->me($antigo)->assertOk();

        $this->travel(SessaoApp::TOLERANCIA_ROTACAO_SEGUNDOS + 5)->seconds();

        // Passada a tolerância, o antigo é cópia: cai e derruba o novo junto.
        $this->me($antigo)->assertStatus(401);
        $this->me($novo)->assertStatus(401);
    }

    public function test_refresh_com_token_ja_rotacionado_derruba_as_sessoes_mobile(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $antigo = $this->loginApp($promotor, 'celular-a');

        $this->novaRequisicao();
        $novo = $this->withToken($antigo)->postJson('/api/auth/refresh')->json('token');

        $this->novaRequisicao();
        $this->withToken($antigo)->postJson('/api/auth/refresh')->assertStatus(401);

        $this->me($novo)->assertStatus(401);
    }

    public function test_token_web_nao_e_renovado(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);
        $tokenWeb = $this->loginWeb($admin);

        $this->novaRequisicao();
        $this->withToken($tokenWeb)->postJson('/api/auth/refresh')->assertForbidden();
    }

    // --- EnsureLicencaValida ---------------------------------------------------------------

    public function test_empresa_bloqueada_com_token_ativo_cai_no_request_seguinte(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $token = $this->loginApp($promotor, 'celular-a');

        $promotor->empresa->update(['ativo' => false]);

        $this->me($token)->assertStatus(401);
    }

    public function test_usuario_desativado_com_token_ativo_cai_no_request_seguinte(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);
        $token = $this->loginWeb($admin);

        $admin->update(['ativo' => false]);

        $this->me($token)->assertStatus(401);
    }

    public function test_aparelho_desvinculado_derruba_a_sessao_mobile(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['senha_hash' => Hash::make('senha-correta')]);
        $token = $this->loginApp($promotor, 'celular-a');

        Dispositivo::where('usuario_id', $promotor->id)->delete();

        $this->me($token)->assertStatus(401);
    }

    // --- B2: limite de licenças também no update -------------------------------------------

    public function test_reativar_promotor_com_limite_cheio_retorna_422(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $inativo = Usuario::factory()->promotor()->inativo()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$inativo->uuid}", ['ativo' => true])->assertStatus(422);
        $this->assertFalse($inativo->fresh()->ativo);
    }

    public function test_trocar_gestor_para_promotor_com_limite_cheio_retorna_422(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$gestor->uuid}", ['user_type' => 'PROMOTOR'])->assertStatus(422);
    }

    public function test_editar_promotor_ativo_com_limite_cheio_continua_permitido(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$promotor->uuid}", ['nome' => 'Nome Novo'])->assertOk();
    }

    // --- B8: limite de tentativas no login -------------------------------------------------

    public function test_sexta_tentativa_de_login_no_minuto_retorna_429(): void
    {
        $admin = Usuario::factory()->admin()->comLicencaMobile()->create(['senha_hash' => Hash::make('senha-correta')]);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'senha' => 'errada'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'senha' => 'senha-correta'])->assertStatus(429);
    }

    // --- B7: licenças visíveis pro ADMIN ---------------------------------------------------

    public function test_admin_ve_uso_igual_ao_do_superadmin_e_os_aparelhos(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => 5]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotor, 'celular-a');
        $this->loginApp($promotor, 'celular-b');

        $this->novaRequisicao();
        Sanctum::actingAs($admin);
        $resposta = $this->getJson('/api/empresa/licencas')->assertOk();

        $resposta->assertJsonPath('uso.licencas_usadas', 1)
            ->assertJsonPath('uso.licencas_limite', 5)
            ->assertJsonPath('uso.licencas_disponiveis', 4)
            ->assertJsonCount(1, 'licenciados')
            ->assertJsonPath('licenciados.0.usuario_uuid', $promotor->uuid)
            ->assertJsonPath('licenciados.0.trocas_aparelho_30d', 1);

        Sanctum::actingAs(Usuario::factory()->superadmin()->create());
        $superadmin = $this->getJson("/api/superadmin/empresas/{$empresa->uuid}")->assertOk();
        $this->assertSame($superadmin->json('uso.licencas_usadas'), $resposta->json('uso.licencas_usadas'));
    }

    public function test_empresa_sem_limite_mostra_disponiveis_nulo(): void
    {
        $empresa = Empresa::factory()->create(['limite_licencas' => null]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->getJson('/api/empresa/licencas')->assertOk()
            ->assertJsonPath('uso.licencas_limite', null)
            ->assertJsonPath('uso.licencas_disponiveis', null);
    }

    public function test_admin_nao_ve_aparelhos_de_outra_empresa(): void
    {
        $outra = Empresa::factory()->create();
        $promotorDeFora = Usuario::factory()->promotor()->create([
            'empresa_id' => $outra->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);
        $this->loginApp($promotorDeFora, 'celular-x');

        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->admin()->create());

        $this->getJson('/api/empresa/licencas')->assertOk()->assertJsonCount(0, 'licenciados');
    }

    public function test_gestor_e_promotor_nao_veem_licencas(): void
    {
        Sanctum::actingAs(Usuario::factory()->gestor()->create());
        $this->getJson('/api/empresa/licencas')->assertForbidden();

        Sanctum::actingAs(Usuario::factory()->promotor()->create());
        $this->getJson('/api/empresa/licencas')->assertForbidden();
    }
}
