<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\EventoSeguranca;
use App\Models\Parametro;
use App\Models\SolicitacaoAparelho;
use App\Models\Usuario;
use App\Support\Atestacao\AppAttest;
use App\Support\Atestacao\Cbor;
use App\Support\Atestacao\PlayIntegrity;
use App\Support\Atestacao\Veredito;
use App\Support\AtestacaoAparelho;
use App\Support\Seguranca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/67-LICENCIAMENTO-E-SESSAO-UNICA.md, Fases 2 e 3 — troca de aparelho limitada, liberação pelo
 * ADMIN, motivo da sessão derrubada, eventos de segurança, versão mínima, anomalias de
 * localização, chave do aparelho com requests assinados e atestação (Play Integrity / App Attest).
 */
class Doc67Fase2e3Test extends TestCase
{
    use RefreshDatabase;

    /** Request novo de verdade — ver Doc67LicenciamentoTest::novaRequisicao. */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->defaultHeaders = [];
    }

    private function promotor(?Empresa $empresa = null): Usuario
    {
        return Usuario::factory()->promotor()->create([
            'empresa_id' => ($empresa ?? Empresa::factory()->create())->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);
    }

    private function loginApp(Usuario $usuario, string $aparelho, array $extra = []): TestResponse
    {
        $this->novaRequisicao();

        return $this->withHeaders(['X-Client' => 'mobile'])->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'senha' => 'senha-correta',
            'dispositivo_identificador' => $aparelho,
            'dispositivo_nome' => "Celular {$aparelho}",
            ...$extra,
        ]);
    }

    private function me(string $token, array $headers = []): TestResponse
    {
        $this->novaRequisicao();

        return $this->withToken($token)->withHeaders($headers)->getJson('/api/auth/me');
    }

    private function parametro(Empresa $empresa, string $chave, string $valor): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => $chave, 'valor' => $valor, 'ativo' => true]);
    }

    // --- Fase 2: troca limitada ----------------------------------------------------------------

    public function test_segunda_troca_dentro_da_janela_e_bloqueada_com_423_e_vira_solicitacao(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a')->assertOk();
        $tokenB = $this->loginApp($promotor, 'b')->assertOk()->json('token');

        $this->loginApp($promotor, 'c')
            ->assertStatus(423)
            ->assertJsonPath('codigo', 'APARELHO_BLOQUEADO')
            ->assertJsonPath('aparelho_vinculado', 'Celular b');

        $this->me($tokenB)->assertOk();
        $this->assertDatabaseHas('solicitacoes_aparelho', [
            'usuario_id' => $promotor->id,
            'identificador_hash' => hash('sha256', 'c'),
            'status' => SolicitacaoAparelho::PENDENTE,
        ]);
        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::TROCA_BLOQUEADA)->exists());
    }

    public function test_tentar_de_novo_o_mesmo_aparelho_bloqueado_nao_duplica_a_solicitacao(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c')->assertStatus(423);
        $this->loginApp($promotor, 'c')->assertStatus(423);

        $this->assertSame(1, SolicitacaoAparelho::withoutGlobalScopes()->count());
    }

    public function test_passada_a_janela_a_troca_propria_volta_a_valer(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');

        $this->travel(8)->days();

        $this->loginApp($promotor, 'c')->assertOk();
    }

    public function test_janela_configurada_abaixo_do_piso_nao_afrouxa(): void
    {
        $promotor = $this->promotor();
        $this->parametro($promotor->empresa, 'TROCA_APARELHO_DIAS', '1');
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');

        $this->travel(2)->days();

        $this->loginApp($promotor, 'c')->assertStatus(423);
    }

    public function test_exige_aprovacao_bloqueia_ate_a_primeira_troca(): void
    {
        $promotor = $this->promotor();
        $this->parametro($promotor->empresa, 'TROCA_APARELHO_EXIGE_APROVACAO', 'true');
        $this->loginApp($promotor, 'a')->assertOk();

        $this->loginApp($promotor, 'b')->assertStatus(423);
    }

    public function test_modo_so_registra_troca_e_grava_que_seria_bloqueada(): void
    {
        $promotor = $this->promotor();
        $this->parametro($promotor->empresa, 'TROCA_APARELHO_SO_REGISTRA', 'true');
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');

        $this->loginApp($promotor, 'c')->assertOk();
        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::TROCA_SERIA_BLOQUEADA)->exists());
    }

    public function test_sessao_derrubada_pela_troca_explica_o_motivo(): void
    {
        $promotor = $this->promotor();
        $tokenA = $this->loginApp($promotor, 'a')->json('token');
        $this->loginApp($promotor, 'b');

        $this->me($tokenA)
            ->assertStatus(401)
            ->assertJsonPath('motivo', 'OUTRO_APARELHO')
            // Hora no fuso da empresa, não em UTC (achado no teste no celular de 12/10/2026).
            ->assertJsonFragment(['message' => 'Sua conta entrou em outro aparelho (Celular b, '
                .now()->setTimezone(\App\Support\Fuso::daEmpresa($promotor->empresa))->format('H:i')
                .'). Se não foi você, avise seu gestor.']);
    }

    public function test_relogar_no_mesmo_aparelho_nao_conta_como_troca(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'a')->assertOk();
        $this->loginApp($promotor, 'b')->assertOk();

        $this->assertSame(2, Dispositivo::where('usuario_id', $promotor->id)->count());
    }

    // --- Fase 2: liberação pelo ADMIN ----------------------------------------------------------

    public function test_admin_aprova_solicitacao_o_atual_cai_e_so_o_aprovado_entra(): void
    {
        $promotor = $this->promotor();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $promotor->empresa_id]);
        $this->loginApp($promotor, 'a');
        $tokenB = $this->loginApp($promotor, 'b')->json('token');
        $this->loginApp($promotor, 'c')->assertStatus(423);
        $solicitacao = SolicitacaoAparelho::withoutGlobalScopes()->sole();

        $this->novaRequisicao();
        Sanctum::actingAs($admin);
        $this->postJson("/api/solicitacoes-aparelho/{$solicitacao->uuid}/aprovar")->assertNoContent();

        $this->me($tokenB)->assertStatus(401)->assertJsonPath('motivo', 'APARELHO_DESVINCULADO');
        $this->loginApp($promotor, 'd')->assertStatus(423);
        $this->loginApp($promotor, 'c')->assertOk();
        $this->assertSame(SolicitacaoAparelho::USADA, $solicitacao->fresh()->status);
        $this->assertSame(Dispositivo::ORIGEM_LIBERADO_ADMIN, $promotor->dispositivo()->first()->origem);
    }

    public function test_admin_recusa_solicitacao(): void
    {
        $promotor = $this->promotor();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $promotor->empresa_id]);
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c');
        $solicitacao = SolicitacaoAparelho::withoutGlobalScopes()->sole();

        $this->novaRequisicao();
        Sanctum::actingAs($admin);
        $this->postJson("/api/solicitacoes-aparelho/{$solicitacao->uuid}/recusar")->assertNoContent();
        $this->postJson("/api/solicitacoes-aparelho/{$solicitacao->uuid}/aprovar")->assertStatus(422);

        $this->assertSame(SolicitacaoAparelho::RECUSADA, $solicitacao->fresh()->status);
    }

    public function test_admin_de_outra_empresa_nao_decide_solicitacao(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c');
        $solicitacao = SolicitacaoAparelho::withoutGlobalScopes()->sole();

        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->admin()->create());
        $this->postJson("/api/solicitacoes-aparelho/{$solicitacao->uuid}/aprovar")->assertNotFound();
    }

    public function test_desvincular_pelo_admin_guarda_historico_e_libera_o_proximo_aparelho(): void
    {
        $promotor = $this->promotor();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $promotor->empresa_id]);
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');

        $this->novaRequisicao();
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo")->assertNoContent();

        // Janela de troca própria já usada, mas o ADMIN liberou: entra sem 423.
        $this->loginApp($promotor, 'c')->assertOk();
        $this->assertSame(3, Dispositivo::where('usuario_id', $promotor->id)->count());
    }

    // --- Fase 2: painel do ADMIN ---------------------------------------------------------------

    public function test_licencas_lista_solicitacoes_e_alerta_quem_troca_demais(): void
    {
        $promotor = $this->promotor();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $promotor->empresa_id]);
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'b');
        $this->loginApp($promotor, 'c');
        // Duas liberações do ADMIN no mês: 3 trocas em 30 dias.
        Dispositivo::where('usuario_id', $promotor->id)->update(['origem' => Dispositivo::ORIGEM_LIBERADO_ADMIN]);
        Dispositivo::create([
            'usuario_id' => $promotor->id, 'empresa_id' => $promotor->empresa_id, 'identificador_hash' => 'x',
            'origem' => Dispositivo::ORIGEM_LIBERADO_ADMIN, 'vinculado_em' => now()->subDay(), 'desvinculado_em' => now()->subDay(),
            'ultimo_acesso_em' => now()->subDay(),
        ]);

        $this->novaRequisicao();
        Sanctum::actingAs($admin);
        $this->getJson('/api/empresa/licencas')->assertOk()
            ->assertJsonPath('licenciados.0.alerta_trocas', true)
            ->assertJsonPath('solicitacoes.0.aparelho', 'Celular c')
            ->assertJsonPath('troca_aparelho.dias_entre_trocas', 7);
    }

    public function test_eventos_de_seguranca_so_da_propria_empresa(): void
    {
        $promotor = $this->promotor();
        $outro = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($outro, 'x');

        $this->novaRequisicao();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $promotor->empresa_id]));
        $eventos = $this->getJson('/api/empresa/eventos-seguranca')->assertOk()->json('eventos');

        $this->assertNotEmpty($eventos);
        $this->assertSame([$promotor->uuid], array_values(array_unique(array_column($eventos, 'usuario_uuid'))));
    }

    // --- Fase 2: versão mínima e anomalias -----------------------------------------------------

    public function test_app_abaixo_da_versao_minima_recebe_426_e_admin_web_nao(): void
    {
        config(['seguranca.versao_minima_app' => '1.2.0']);
        $promotor = $this->promotor();

        $this->withHeaders(['X-Client' => 'mobile', 'X-App-Version' => '1.1.9'])->postJson('/api/auth/login', [
            'email' => $promotor->email, 'senha' => 'senha-correta', 'dispositivo_identificador' => 'a',
        ])->assertStatus(426)->assertJsonPath('codigo', 'APP_DESATUALIZADO');

        $this->novaRequisicao();
        $this->withHeaders(['X-Client' => 'mobile'])->postJson('/api/auth/login', [
            'email' => $promotor->email, 'senha' => 'senha-correta', 'dispositivo_identificador' => 'a',
        ])->assertStatus(426);

        $this->novaRequisicao();
        $this->withHeaders(['X-Client' => 'mobile', 'X-App-Version' => '1.2.0'])->postJson('/api/auth/login', [
            'email' => $promotor->email, 'senha' => 'senha-correta', 'dispositivo_identificador' => 'a',
        ])->assertOk();

        $admin = Usuario::factory()->admin()->create(['senha_hash' => Hash::make('senha-correta')]);
        $this->novaRequisicao();
        $this->withHeaders(['X-Client' => 'admin'])->postJson('/api/auth/login', [
            'email' => $admin->email, 'senha' => 'senha-correta',
        ])->assertOk();
    }

    public function test_viagem_impossivel_e_gps_simulado_viram_evento_sem_recusar_a_posicao(): void
    {
        $promotor = $this->promotor();
        $this->parametro($promotor->empresa, 'RASTREAMENTO_INTERVALO_SEGUNDOS', '60');
        $token = $this->loginApp($promotor, 'a')->json('token');

        $this->novaRequisicao();
        $this->withToken($token)->patchJson('/api/localizacao', [
            'latitude' => -23.5505, 'longitude' => -46.6333, 'capturado_em' => now()->subMinutes(2)->toIso8601String(),
        ])->assertNoContent();
        // Rio de Janeiro dois minutos depois: ~360 km.
        $this->novaRequisicao();
        $this->withToken($token)->patchJson('/api/localizacao', [
            'latitude' => -22.9068, 'longitude' => -43.1729, 'capturado_em' => now()->toIso8601String(), 'simulada' => true,
        ])->assertNoContent();

        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::VIAGEM_IMPOSSIVEL)->exists());
        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::LOCALIZACAO_SIMULADA)->exists());
    }

    // --- Fase 3: chave do aparelho e requests assinados ----------------------------------------

    /**
     * Gerar chave no OpenSSL do PHP no Windows (XAMPP) exige apontar o openssl.cnf; no Linux/CI o
     * padrão do sistema já serve. A API em si só verifica assinatura, nunca gera chave.
     */
    private function opcoesOpenssl(array $opcoes = []): array
    {
        $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';

        return is_file($cnf) ? [...$opcoes, 'config' => $cnf] : $opcoes;
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} chave privada e pública (ponto base64) */
    private function parDeChaves(): array
    {
        $privada = openssl_pkey_new($this->opcoesOpenssl(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]));
        $d = openssl_pkey_get_details($privada)['ec'];
        $ponto = "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT);

        return [$privada, base64_encode($ponto)];
    }

    private function assinatura(\OpenSSLAsymmetricKey $privada, string $metodo, string $caminho, string $token, ?int $em = null): array
    {
        $em ??= time();
        $hashToken = hash('sha256', explode('|', $token, 2)[1]);
        openssl_sign("{$metodo}\n{$caminho}\n{$em}\n{$hashToken}", $assinatura, $privada, OPENSSL_ALGO_SHA256);

        return ['X-Assinatura-Em' => (string) $em, 'X-Assinatura' => base64_encode($assinatura)];
    }

    public function test_aparelho_com_chave_exige_request_assinado(): void
    {
        [$privada, $publica] = $this->parDeChaves();
        $promotor = $this->promotor();
        $token = $this->loginApp($promotor, 'a', ['chave_publica' => $publica])->assertOk()->json('token');

        $this->me($token)->assertStatus(401)->assertJsonPath('motivo', 'ASSINATURA_INVALIDA');
        $this->me($token, $this->assinatura($privada, 'GET', '/api/auth/me', $token))->assertOk();
        $this->me($token, $this->assinatura($privada, 'GET', '/api/auth/me', $token, time() - 3600))->assertStatus(401);
        $this->me($token, $this->assinatura($privada, 'POST', '/api/auth/me', $token))->assertStatus(401);

        [$outraChave] = $this->parDeChaves();
        $this->me($token, $this->assinatura($outraChave, 'GET', '/api/auth/me', $token))->assertStatus(401);
        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::ASSINATURA_INVALIDA)->exists());
    }

    public function test_sem_chave_continua_funcionando_sem_assinatura_expo_go(): void
    {
        $promotor = $this->promotor();
        $token = $this->loginApp($promotor, 'a')->assertOk()->json('token');

        $this->me($token)->assertOk();
        $this->assertSame('NAO_ATESTADO', $promotor->dispositivo()->first()->integridade_status);
    }

    public function test_chave_publica_invalida_e_ignorada(): void
    {
        $this->assertNull(AtestacaoAparelho::pem(base64_encode('lixo')));
        $this->assertNull(AtestacaoAparelho::pem(null));
        [, $publica] = $this->parDeChaves();
        $this->assertNotNull(AtestacaoAparelho::pem($publica));
    }

    // --- Fase 3: atestação ---------------------------------------------------------------------

    public function test_desafio_e_de_uso_unico(): void
    {
        [, $publica] = $this->parDeChaves();
        $promotor = $this->promotor();
        $desafio = $this->postJson('/api/auth/desafio')->assertOk()->assertJsonStructure(['desafio', 'expira_em', 'obrigatoria'])->json('desafio');

        $primeira = AtestacaoAparelho::exigir($promotor, ['desafio' => $desafio, 'plataforma' => 'android', 'token' => 't'], $publica);
        $segunda = AtestacaoAparelho::exigir($promotor, ['desafio' => $desafio, 'plataforma' => 'android', 'token' => 't'], $publica);

        // Sem Play Integrity configurado o serviço conta como indisponível; o 2º uso do desafio falha.
        $this->assertSame(Veredito::INDISPONIVEL, $primeira->status);
        $this->assertSame(Veredito::FALHOU, $segunda->status);
    }

    public function test_atestacao_obrigatoria_bloqueia_login_sem_atestacao(): void
    {
        config(['seguranca.atestacao_obrigatoria' => true]);
        $promotor = $this->promotor();

        $this->loginApp($promotor, 'a')->assertForbidden()->assertJsonPath('codigo', 'ATESTACAO_FALHOU');
        $this->assertNull($promotor->dispositivo()->first());
    }

    public function test_atestacao_obrigatoria_com_google_fora_do_ar_nao_bloqueia(): void
    {
        config(['seguranca.atestacao_obrigatoria' => true]);
        [, $publica] = $this->parDeChaves();
        $promotor = $this->promotor();
        $desafio = $this->postJson('/api/auth/desafio')->json('desafio');

        $this->loginApp($promotor, 'a', [
            'chave_publica' => $publica,
            'atestacao' => ['desafio' => $desafio, 'plataforma' => 'android', 'token' => 'qualquer'],
        ])->assertOk();
    }

    public function test_play_integrity_ponta_a_ponta_com_google_simulado(): void
    {
        config(['seguranca.atestacao_obrigatoria' => true]);
        $credenciais = tempnam(sys_get_temp_dir(), 'pi');
        $rsa = openssl_pkey_new($this->opcoesOpenssl(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
        openssl_pkey_export($rsa, $pemPrivado, null, $this->opcoesOpenssl());
        file_put_contents($credenciais, json_encode([
            'client_email' => 'horus@projeto.iam.gserviceaccount.com',
            'private_key' => $pemPrivado,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['seguranca.play_integrity.credenciais' => $credenciais]);

        [, $publica] = $this->parDeChaves();
        $promotor = $this->promotor();
        $desafio = $this->postJson('/api/auth/desafio')->json('desafio');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'acesso']),
            'playintegrity.googleapis.com/*' => Http::response(['tokenPayloadExternal' => [
                'requestDetails' => ['requestHash' => AtestacaoAparelho::hashPedido($desafio, $publica), 'timestampMillis' => now()->getTimestampMs()],
                'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => 'com.prossigatec.horus'],
                'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
            ]]),
        ]);

        $this->loginApp($promotor, 'a', [
            'chave_publica' => $publica,
            'atestacao' => ['desafio' => $desafio, 'plataforma' => 'android', 'token' => 'token-do-google'],
        ])->assertOk();

        $this->assertSame('ATESTADO', $promotor->dispositivo()->first()->integridade_status);
        unlink($credenciais);
    }

    public function test_play_integrity_reprova_vereditos_negativos(): void
    {
        $config = ['pacote' => 'com.prossigatec.horus', 'certificado_sha256' => ''];
        $base = [
            'requestDetails' => ['requestHash' => 'h', 'timestampMillis' => now()->getTimestampMs()],
            'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => 'com.prossigatec.horus'],
            'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
        ];

        $this->assertSame(Veredito::OK, PlayIntegrity::avaliar($base, 'h', $config)->status);
        $this->assertSame(Veredito::FALHOU, PlayIntegrity::avaliar($base, 'outro', $config)->status);

        $modificado = $base;
        $modificado['appIntegrity']['appRecognitionVerdict'] = 'UNRECOGNIZED_VERSION';
        $this->assertSame(Veredito::FALHOU, PlayIntegrity::avaliar($modificado, 'h', $config)->status);

        $emulador = $base;
        $emulador['deviceIntegrity']['deviceRecognitionVerdict'] = [];
        $this->assertSame(Veredito::FALHOU, PlayIntegrity::avaliar($emulador, 'h', $config)->status);

        $antigo = $base;
        $antigo['requestDetails']['timestampMillis'] = now()->subHour()->getTimestampMs();
        $this->assertSame(Veredito::FALHOU, PlayIntegrity::avaliar($antigo, 'h', $config)->status);

        $certificado = ['pacote' => 'com.prossigatec.horus', 'certificado_sha256' => str_repeat('ab', 32)];
        $comCert = $base;
        $comCert['appIntegrity']['certificateSha256Digest'] = [rtrim(strtr(base64_encode(str_repeat("\xAB", 32)), '+/', '-_'), '=')];
        $this->assertSame(Veredito::OK, PlayIntegrity::avaliar($comCert, 'h', $certificado)->status);
        $comCert['appIntegrity']['certificateSha256Digest'] = [base64_encode(str_repeat("\x01", 32))];
        $this->assertSame(Veredito::FALHOU, PlayIntegrity::avaliar($comCert, 'h', $certificado)->status);
    }

    public function test_app_attest_assercao_valida_e_repeticao_recusada(): void
    {
        config(['seguranca.app_attest.team_id' => 'TEAM123456', 'seguranca.app_attest.bundle_id' => 'com.prossigatec.horus']);
        [$privada] = $this->parDeChaves();
        $pem = openssl_pkey_get_details($privada)['key'];

        $assercao = function (int $contador, string $desafio) use ($privada): string {
            $authData = hash('sha256', 'TEAM123456.com.prossigatec.horus', true)."\x00".pack('N', $contador);
            $nonce = hash('sha256', $authData.hash('sha256', $desafio, true), true);
            openssl_sign($nonce, $assinatura, $privada, OPENSSL_ALGO_SHA256);

            // CBOR: map(2) {"signature": bytes, "authenticatorData": bytes}
            $bytes = fn (string $b): string => (strlen($b) < 24 ? chr(0x40 | strlen($b)) : "\x58".chr(strlen($b))).$b;

            return base64_encode("\xA2"."\x69signature".$bytes($assinatura)."\x71authenticatorData".$bytes($authData));
        };

        $this->assertSame(Veredito::OK, AppAttest::verificarAssercao($assercao(5, 'd1'), 'd1', $pem, 4)->status);
        $this->assertSame(Veredito::FALHOU, AppAttest::verificarAssercao($assercao(5, 'd1'), 'd1', $pem, 5)->status);
        $this->assertSame(Veredito::FALHOU, AppAttest::verificarAssercao($assercao(6, 'd1'), 'outro', $pem, 5)->status);
    }

    public function test_app_attest_sem_configuracao_e_indisponivel_e_lixo_falha(): void
    {
        config(['seguranca.app_attest.team_id' => '']);
        $this->assertSame(Veredito::INDISPONIVEL, AppAttest::verificarAtestacao('a', 'b', 'c')->status);

        config(['seguranca.app_attest.team_id' => 'T', 'seguranca.app_attest.raiz' => __FILE__]);
        $this->assertSame(Veredito::FALHOU, AppAttest::verificarAtestacao(base64_encode('k'), base64_encode('lixo'), 'c')->status);
    }

    public function test_cbor_decodifica_mapa_aninhado(): void
    {
        // {"fmt": "apple-appattest", "n": [1, -2]}
        $cbor = "\xA2\x63fmt\x6Fapple-appattest\x61n\x82\x01\x21";
        $this->assertSame(['fmt' => 'apple-appattest', 'n' => [1, -2]], Cbor::decodificar($cbor));
    }

    // --- Fase 3: sinais de root/emulador -------------------------------------------------------

    public function test_root_em_modo_alerta_entra_e_avisa(): void
    {
        $promotor = $this->promotor();

        $this->loginApp($promotor, 'a', ['integridade' => ['root' => true]])->assertOk();

        $this->assertSame('SUSPEITO', $promotor->dispositivo()->first()->integridade_status);
        $this->assertTrue(EventoSeguranca::where('tipo', Seguranca::INTEGRIDADE_ALERTA)->exists());
    }

    public function test_emulador_em_modo_bloqueio_e_negado(): void
    {
        $promotor = $this->promotor();
        $this->parametro($promotor->empresa, 'INTEGRIDADE_MODO', 'BLOQUEIO');

        $this->loginApp($promotor, 'a', ['integridade' => ['emulador' => true]])
            ->assertForbidden()
            ->assertJsonPath('codigo', 'INTEGRIDADE_BLOQUEADA');
    }

    public function test_limpeza_apaga_tokens_revogados_antigos(): void
    {
        $promotor = $this->promotor();
        $this->loginApp($promotor, 'a');
        $this->loginApp($promotor, 'a');

        $this->travel(31)->days();
        $this->artisan('sessoes:limpar')->assertSuccessful();

        $this->assertSame(1, $promotor->tokens()->count());
    }
}
