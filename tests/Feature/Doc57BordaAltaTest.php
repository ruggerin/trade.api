<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Casos de borda de prioridade Alta da matriz (docs/57, Parte A) que não tinham cobertura:
 * #5 raio 199/200/201 m, #10 token antigo de usuário desativado, #13 check-ins simultâneos e
 * #16 acento/emoji/HTML/SQL. O # de cada caso está no nome do teste, pra coluna A do CSV.
 */
class Doc57BordaAltaTest extends TestCase
{
    use RefreshDatabase;

    /** Metros por grau de latitude na esfera de Haversine::metros (R = 6.371.000 m). */
    private const METROS_POR_GRAU = 111194.9266;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        // Raio explícito: o teste não pode depender do .env de quem roda.
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '200', 'ativo' => true]);
    }

    private function checkinA(Usuario $promotor, PontoVenda $pdv, float $metros): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => (float) $pdv->latitude + $metros / self::METROS_POR_GRAU,
            'longitude' => (float) $pdv->longitude,
        ]);
    }

    /** O guard do sanctum memoiza o usuário entre requests simulados — ver LoginTest (derruba sessão). */
    private function esquecerGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    // ---- #5 GPS: raio ----

    public function test_caso5_checkin_aceita_ate_o_raio_e_recusa_logo_acima(): void
    {
        $casos = [
            [199.0, 201],
            [199.9, 201],
            [200.1, 422],
            [201.0, 422],
        ];

        foreach ($casos as [$metros, $status]) {
            $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
            $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);

            $resposta = $this->checkinA($promotor, $pdv, $metros);
            $resposta->assertStatus($status);

            if ($status === 422) {
                $this->assertGreaterThan(200, $resposta->json('distancia_metros'), "{$metros} m deveria passar de 200");
                $this->assertDatabaseMissing('visitas', ['usuario_id' => $promotor->id]);
            } else {
                $this->assertDatabaseHas('visitas', ['usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id]);
            }
        }
    }

    // ---- #6 GPS: precisão pior que o raio ----

    public function test_caso6_precisao_do_gps_pior_que_o_raio_pede_justificativa(): void
    {
        $this->markTestSkipped(
            'docs/57 #6: a regra não existe — nem o app (mobile/src/lib/location/useLocalizacaoAtual.ts só pede '
            .'accuracy High) nem a API recebem ou checam a precisão do GPS. Decisão de produto antes do teste.'
        );
    }

    // ---- #10 usuário desativado / token antigo ----

    public function test_caso10_token_antigo_de_usuario_desativado_e_negado_e_nao_volta_se_reativar(): void
    {
        $usuario = Usuario::factory()->admin()->create([
            'empresa_id' => $this->empresa->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);

        $token = $this->postJson('/api/auth/login', ['email' => $usuario->email, 'senha' => 'senha-correta'])
            ->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $usuario->update(['ativo' => false]);
        $this->esquecerGuards();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('motivo', 'USUARIO_INATIVO');

        // Reativar não ressuscita o token que foi derrubado: tem que logar de novo.
        $usuario->update(['ativo' => true]);
        $this->esquecerGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $usuario->email, 'senha' => 'senha-correta'])->assertOk();
    }

    public function test_caso10_token_antigo_de_empresa_bloqueada_e_negado(): void
    {
        $usuario = Usuario::factory()->admin()->create([
            'empresa_id' => $this->empresa->id,
            'senha_hash' => Hash::make('senha-correta'),
        ]);
        $token = $this->postJson('/api/auth/login', ['email' => $usuario->email, 'senha' => 'senha-correta'])->json('token');

        $this->empresa->update(['ativo' => false]);
        $this->esquecerGuards();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('motivo', 'EMPRESA_BLOQUEADA');
    }

    // ---- #13 concorrência: check-ins simultâneos ----

    public function test_caso13_checkin_repetido_na_mesma_loja_nao_cria_segunda_visita(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);

        $primeira = $this->checkinA($promotor, $pdv, 0);
        $segunda = $this->checkinA($promotor, $pdv, 0);

        $primeira->assertCreated();
        $segunda->assertOk()->assertJsonPath('retomada', true);
        $this->assertSame(1, Visita::withoutGlobalScopes()->where('usuario_id', $promotor->id)->count());
        $this->assertSame($primeira->json('visita.id'), $segunda->json('visita.id'));
    }

    public function test_caso13_corrida_com_a_mesma_idempotency_key_devolve_a_visita_vencedora_e_nao_500(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $chave = (string) Str::uuid();
        $vencedora = null;

        // Simula a outra requisição ganhando a corrida: a visita dela entra no banco DEPOIS de
        // esta ter passado pela checagem de idempotência (que vem antes de carregar a loja) e ANTES
        // do insert — o caso que antes dava 500. Fora da transação, como a outra requisição real.
        PontoVenda::retrieved(function (PontoVenda $loja) use (&$vencedora, $chave, $promotor, $pdv): void {
            static $feito = false;
            if ($feito) {
                return;
            }
            $feito = true;
            $vencedora = Visita::withoutEvents(fn () => Visita::create([
                'empresa_id' => $this->empresa->id,
                'ponto_venda_id' => $pdv->id,
                'usuario_id' => $promotor->id,
                'idempotency_key' => $chave,
                // FINALIZADA: escapa das checagens de "já tem visita aberta" e cai na constraint única.
                'status' => 'FINALIZADA',
                'inicio_data' => now()->subMinutes(5),
                'fim_data' => now(),
                'inicio_latitude' => $pdv->latitude,
                'inicio_longitude' => $pdv->longitude,
                'inicio_distancia_metros' => 0,
            ]));
        });

        Sanctum::actingAs($promotor);
        $resposta = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'idempotency_key' => $chave,
        ]);

        $resposta->assertOk()->assertJsonPath('visita.id', $vencedora->refresh()->uuid);
        $this->assertSame(1, Visita::withoutGlobalScopes()->where('idempotency_key', $chave)->count());
    }

    // ---- #16 acento, emoji, aspas, HTML e SQL ----

    public function test_caso16_texto_com_acento_emoji_html_e_sql_e_salvo_e_devolvido_igual(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $textos = [
            'Açaí & Pão d\'Água — "Promoção" 🍎',
            '<script>alert("x")</script><b>negrito</b>',
            "Robert'); DROP TABLE redes_lojas;--",
            "' OR '1'='1",
            '日本語 ñandú ÀÉÎÕÜ',
        ];

        foreach ($textos as $texto) {
            $criada = $this->postJson('/api/redes-lojas', ['descricao' => $texto])->assertCreated();
            // JSON, não HTML: o servidor devolve o texto cru; escapar é do front (React).
            $this->assertSame($texto, $criada->json('rede_loja.descricao'));
            $this->assertDatabaseHas('redes_lojas', ['empresa_id' => $this->empresa->id, 'descricao' => $texto]);
        }

        $lista = collect($this->getJson('/api/redes-lojas')->assertOk()->json('redes_lojas'))->pluck('descricao');
        foreach ($textos as $texto) {
            $this->assertTrue($lista->contains($texto), "faltou na lista: {$texto}");
        }
        // Se o DROP TABLE tivesse executado, a lista acima já teria estourado.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('redes_lojas'));
    }

    public function test_caso16_busca_com_aspas_percent_e_sql_nao_quebra_nem_vaza(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja Açaí']);
        $outra = Empresa::factory()->create();
        PontoVenda::factory()->create(['empresa_id' => $outra->id, 'fantasia' => 'Loja Alheia']);

        foreach (["' OR 1=1 --", '%', '_', "\\", '"; DROP TABLE pontos_venda; --', '🍎'] as $busca) {
            $resposta = $this->getJson('/api/pontos-venda?'.http_build_query(['busca' => $busca]));
            $resposta->assertOk();
            $nomes = collect($resposta->json('pontos_venda'))->pluck('fantasia');
            $this->assertFalse($nomes->contains('Loja Alheia'), "busca '{$busca}' vazou loja de outra empresa");
        }

        $this->getJson('/api/pontos-venda?'.http_build_query(['busca' => 'açaí']))
            ->assertOk()->assertJsonFragment(['fantasia' => 'Loja Açaí']);
    }
}
