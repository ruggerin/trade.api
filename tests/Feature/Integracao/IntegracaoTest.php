<?php

namespace Tests\Feature\Integracao;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\ImagemRegistro;
use App\Models\Integracao;
use App\Models\PersonalAccessToken;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\SessaoApp;
use App\Support\TokenIntegracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/96 — tokens de API e integrações: os testes obrigatórios da Fase 1 (§10) e o contrato de
 * leitura da Fase 2.
 */
class IntegracaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
    }

    /** Cria pela rota do admin e devolve [integração, token em texto puro]. */
    private function criarIntegracao(array $dados = []): array
    {
        Sanctum::actingAs($this->admin);
        $resposta = $this->postJson('/api/integracoes', $dados + [
            'nome' => 'Power BI',
            'escopos' => ['visitas:ler'],
            'validade_dias' => 365,
        ])->assertCreated();
        $this->esquecerLogin();

        return [Integracao::where('uuid', $resposta->json('integracao.uuid'))->firstOrFail(), $resposta->json('token')];
    }

    private function getComToken(string $token, string $uri, array $headers = []): TestResponse
    {
        $this->esquecerLogin();

        return $this->withHeaders(['Authorization' => "Bearer {$token}"] + $headers)->getJson($uri);
    }

    /**
     * Volta ao estado de uma requisição nova. Além de esquecer o usuário, devolve o guard padrão
     * pro `web`: o Sanctum::actingAs deixa o `sanctum` como padrão, e aí o BelongsToEmpresa do dono
     * do token chamaria o próprio guard que ainda o está carregando (em produção o padrão é `web`
     * enquanto o auth:sanctum autentica).
     */
    private function esquecerLogin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
    }

    private function visitaDaEmpresa(Empresa $empresa, array $atributos = []): Visita
    {
        return Visita::factory()->create($atributos + [
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $empresa->id])->id,
            'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
        ]);
    }

    // ── Gestão (só ADMIN) ────────────────────────────────────────────────────────────────

    public function test_admin_cria_integracao_e_recebe_o_token_uma_vez_so(): void
    {
        [$integracao, $token] = $this->criarIntegracao();

        $this->assertStringStartsWith('horus_test_', $token);
        $this->assertSame(51, strlen($token));

        $registro = PersonalAccessToken::where('tokenable_id', $integracao->id)->where('tokenable_type', $integracao->getMorphClass())->sole();
        $this->assertSame(hash('sha256', $token), $registro->token);
        $this->assertSame(TokenIntegracao::CANAL, $registro->canal);
        $this->assertSame(['visitas:ler'], $registro->abilities);
        $this->assertTrue($registro->expires_at->between(now()->addDays(364), now()->addDays(366)));

        Sanctum::actingAs($this->admin);
        $lista = $this->getJson('/api/integracoes')->assertOk();
        $detalhe = $this->getJson("/api/integracoes/{$integracao->uuid}")->assertOk();
        $this->assertStringNotContainsString($token, $lista->getContent());
        $this->assertStringNotContainsString($token, $detalhe->getContent());
        $detalhe->assertJsonPath('integracao.tokens.0.prefixo', mb_substr($token, 0, 15).'…')
            ->assertJsonPath('integracao.situacao', 'ativa')
            ->assertJsonPath('integracao.criada_por', $this->admin->nome);
    }

    public function test_gestor_mesmo_com_todas_as_permissoes_e_promotor_nao_gerenciam_integracoes(): void
    {
        $gestor = Usuario::factory()->gestor()->comPerfil(array_map(fn ($p) => $p->value, Permissao::cases()))->create(['empresa_id' => $this->empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);

        foreach ([$gestor, $promotor] as $usuario) {
            Sanctum::actingAs($usuario);
            $this->getJson('/api/integracoes')->assertForbidden();
            $this->postJson('/api/integracoes', ['nome' => 'X', 'escopos' => ['visitas:ler']])->assertForbidden();
        }
    }

    public function test_token_nao_nasce_sem_permissao_nem_com_escopo_ainda_indisponivel(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/integracoes', ['nome' => 'X', 'escopos' => []])->assertUnprocessable()->assertJsonValidationErrors('escopos');
        $this->postJson('/api/integracoes', ['nome' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('escopos');
        $this->postJson('/api/integracoes', ['nome' => 'X', 'escopos' => ['agenda:escrever']])->assertUnprocessable()->assertJsonValidationErrors('escopos.0');
        $this->postJson('/api/integracoes', ['nome' => 'X', 'escopos' => ['*']])->assertUnprocessable();
        $this->postJson('/api/integracoes', ['nome' => 'X', 'escopos' => ['visitas:ler'], 'ips_permitidos' => ['999.1.1.1']])->assertUnprocessable()->assertJsonValidationErrors('ips_permitidos.0');
    }

    public function test_admin_de_outra_empresa_nao_ve_a_integracao(): void
    {
        [$integracao] = $this->criarIntegracao();
        $outraEmpresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]));

        $this->getJson('/api/integracoes')->assertOk()->assertJsonCount(0, 'integracoes');
        $this->getJson("/api/integracoes/{$integracao->uuid}")->assertNotFound();
        $this->postJson("/api/integracoes/{$integracao->uuid}/revogar", ['motivo' => 'x'])->assertNotFound();
    }

    // ── Segurança do token ───────────────────────────────────────────────────────────────

    public function test_token_le_so_dados_da_propria_empresa(): void
    {
        [, $token] = $this->criarIntegracao();
        $minha = $this->visitaDaEmpresa($this->empresa);
        $this->visitaDaEmpresa(Empresa::factory()->create());

        $this->getComToken($token, '/api/integracao/v1/visitas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $minha->uuid)
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_escopo_faltando_da_403_dizendo_qual(): void
    {
        [, $token] = $this->criarIntegracao(['escopos' => ['visitas:ler']]);

        $this->getComToken($token, '/api/integracao/v1/xp')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 403)
            ->assertJsonPath('detail', 'Este token não tem a permissão xp:ler.');
    }

    public function test_editar_escopos_vale_na_hora(): void
    {
        [$integracao, $token] = $this->criarIntegracao(['escopos' => ['visitas:ler']]);
        $this->getComToken($token, '/api/integracao/v1/lojas')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/integracoes/{$integracao->uuid}", ['escopos' => ['lojas:ler']])->assertOk();

        $this->getComToken($token, '/api/integracao/v1/lojas')->assertOk();
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertForbidden();
    }

    public function test_token_revogado_ou_vencido_da_401(): void
    {
        [$integracao, $token] = $this->criarIntegracao(['validade_dias' => 30]);
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertOk();

        $this->travel(31)->days();
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertUnauthorized()->assertJsonPath('status', 401);
        $this->travelBack();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/integracoes/{$integracao->uuid}/revogar", ['motivo' => 'vazou'])->assertOk()->assertJsonPath('integracao.situacao', 'revogada');
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertUnauthorized();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/integracoes/{$integracao->uuid}/reativar")->assertStatus(409);
    }

    public function test_token_de_pessoa_nao_entra_na_api_de_integracao(): void
    {
        $tokenAdmin = SessaoApp::emitir($this->admin, SessaoApp::CANAL_WEB, request());

        $this->getComToken($tokenAdmin, '/api/integracao/v1/visitas')->assertUnauthorized();
    }

    public function test_token_de_integracao_nao_abre_o_app_nem_o_admin(): void
    {
        [, $token] = $this->criarIntegracao();

        $this->getComToken($token, '/api/auth/me')->assertUnauthorized();
        $this->getComToken($token, '/api/visitas')->assertUnauthorized();
        $this->getComToken($token, '/api/integracoes')->assertUnauthorized();
    }

    public function test_sem_token_ou_token_inventado_da_401(): void
    {
        $this->getJson('/api/integracao/v1/visitas')->assertUnauthorized()->assertJsonPath('status', 401);
        $this->getComToken('horus_test_'.str_repeat('a', 40), '/api/integracao/v1/visitas')->assertUnauthorized();
    }

    public function test_limite_por_minuto_da_429(): void
    {
        [$integracao, $token] = $this->criarIntegracao(['limite_por_minuto' => 2]);
        RateLimiter::clear('integracao:'.$integracao->id);

        $this->getComToken($token, '/api/integracao/v1/visitas')->assertOk()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertOk();
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_ip_fora_da_lista_da_403(): void
    {
        [, $token] = $this->criarIntegracao(['ips_permitidos' => ['10.0.0.0/8']]);

        $this->getComToken($token, '/api/integracao/v1/visitas')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertOk();
    }

    public function test_integracao_pausada_ou_empresa_bloqueada_da_403(): void
    {
        [$integracao, $token] = $this->criarIntegracao();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/integracoes/{$integracao->uuid}/pausar")->assertOk()->assertJsonPath('integracao.situacao', 'pausada');
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/integracoes/{$integracao->uuid}/reativar")->assertOk();
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertOk();

        $this->empresa->update(['ativo' => false]);
        $this->getComToken($token, '/api/integracao/v1/visitas')->assertForbidden();
    }

    public function test_rotacao_mantem_os_dois_validos_e_depois_o_antigo_vence(): void
    {
        [$integracao, $antigo] = $this->criarIntegracao();

        Sanctum::actingAs($this->admin);
        $novo = $this->postJson("/api/integracoes/{$integracao->uuid}/rotacionar", ['validade_dias' => 365])
            ->assertOk()
            ->assertJsonCount(2, 'integracao.tokens')
            ->assertJsonPath('integracao.tokens.1.antigo', true)
            ->json('token');
        $this->assertNotSame($antigo, $novo);

        $this->getComToken($antigo, '/api/integracao/v1/visitas')->assertOk();
        $this->getComToken($novo, '/api/integracao/v1/visitas')->assertOk();

        $this->travel(TokenIntegracao::TOLERANCIA_ROTACAO_DIAS + 1)->days();
        $this->getComToken($antigo, '/api/integracao/v1/visitas')->assertUnauthorized();
        $this->getComToken($novo, '/api/integracao/v1/visitas')->assertOk();
    }

    public function test_auditoria_grava_cada_chamada_sem_o_segredo(): void
    {
        [$integracao, $token] = $this->criarIntegracao();
        $this->visitaDaEmpresa($this->empresa);

        $this->getComToken($token, '/api/integracao/v1/visitas?limit=10')->assertOk();
        $this->getComToken($token, '/api/integracao/v1/xp')->assertForbidden();

        $requisicoes = $integracao->requisicoes()->orderBy('id')->get();
        $this->assertCount(2, $requisicoes);
        $this->assertSame(['/api/integracao/v1/visitas', 200, 1], [$requisicoes[0]->rota, $requisicoes[0]->status, $requisicoes[0]->registros]);
        $this->assertSame([403, 'Este token não tem a permissão xp:ler.'], [$requisicoes[1]->status, $requisicoes[1]->erro]);
        $this->assertNotNull($integracao->fresh()->ultimo_uso_em);

        Sanctum::actingAs($this->admin);
        $auditoria = $this->getJson("/api/integracoes/{$integracao->uuid}/requisicoes?so_erros=1")->assertOk()->assertJsonCount(1, 'requisicoes');
        $this->assertStringNotContainsString($token, $auditoria->getContent());
        $this->assertDatabaseMissing('integracao_requisicoes', ['rota' => $token]);
    }

    // ── Leitura (Fase 2) ─────────────────────────────────────────────────────────────────

    public function test_paginacao_por_cursor_e_leitura_incremental(): void
    {
        [, $token] = $this->criarIntegracao();
        $visitas = collect(range(1, 3))->map(fn () => $this->visitaDaEmpresa($this->empresa));
        Visita::whereKey($visitas[0]->id)->update(['updated_at' => now()->subDays(5)]);

        $pagina1 = $this->getComToken($token, '/api/integracao/v1/visitas?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($visitas[0]->uuid, $pagina1->json('data.0.uuid'));
        $cursor = $pagina1->json('next_cursor');
        $this->assertNotNull($cursor);

        $pagina2 = $this->getComToken($token, '/api/integracao/v1/visitas?limit=2&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull($pagina2->json('next_cursor'));

        $desde = now()->subDay()->toIso8601ZuluString();
        $this->getComToken($token, '/api/integracao/v1/visitas?updated_since='.urlencode($desde))->assertOk()->assertJsonCount(2, 'data');
        $this->getComToken($token, '/api/integracao/v1/visitas?limit=5000')->assertUnprocessable()->assertJsonPath('status', 422)->assertJsonStructure(['errors' => ['limit']]);
    }

    public function test_usuarios_nao_expoe_dado_pessoal(): void
    {
        [, $token] = $this->criarIntegracao(['escopos' => ['usuarios:ler']]);

        $dados = $this->getComToken($token, '/api/integracao/v1/usuarios')->assertOk()->json('data.0');
        $this->assertSame(['uuid', 'nome', 'codigo_externo', 'tipo', 'supervisor_uuid', 'ativo', 'alterado_em'], array_keys($dados));
    }

    public function test_registros_trazem_link_temporario_da_foto(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $token] = $this->criarIntegracao(['escopos' => ['registros:ler', 'rupturas:ler']]);
        $visita = $this->visitaDaEmpresa($this->empresa);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura']);
        $registro = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'ruptura' => true, 'observacao' => 'Gôndola vazia']);
        Storage::disk(config('filesystems.default'))->put('fotos/a.jpg', 'conteudo');
        $imagem = ImagemRegistro::create(['visita_id' => $visita->id, 'caminho' => 'fotos/a.jpg']);
        $registro->imagens()->attach($imagem->id, ['ordem' => 1]);

        $url = $this->getComToken($token, '/api/integracao/v1/registros')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $registro->uuid)
            ->json('data.0.fotos.0.url');
        $this->getComToken($token, '/api/integracao/v1/rupturas')->assertOk()->assertJsonCount(1, 'data');

        $this->esquecerLogin();
        $this->withHeaders(['Authorization' => ''])->get($url)->assertOk();
        $this->get("/api/integracao/v1/fotos/{$imagem->uuid}")->assertForbidden();
    }
}
