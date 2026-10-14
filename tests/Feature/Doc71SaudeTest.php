<?php

namespace Tests\Feature;

use App\Support\Saude\VerificacaoSaude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Endpoint de saúde pro Uptime Kuma (docs/71): testa de verdade e só dá 200 quando está tudo certo. */
class Doc71SaudeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-bem-longo-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['saude.token' => self::TOKEN]);
        // Agendador vivo: o batimento mais recente é de agora.
        Cache::put(VerificacaoSaude::CHAVE_BATIMENTO, now()->getTimestamp(), 3600);
    }

    private function comToken(): array
    {
        return ['X-Saude-Token' => self::TOKEN];
    }

    public function test_tudo_certo_responde_200_ok_sem_detalhe_para_quem_nao_tem_token(): void
    {
        $r = $this->getJson('/api/saude')->assertOk();

        $r->assertJsonPath('status', 'ok');
        $this->assertArrayNotHasKey('verificacoes', $r->json());
        $this->assertArrayNotHasKey('app', $r->json());
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_com_token_traz_o_detalhe_de_cada_verificacao(): void
    {
        $r = $this->getJson('/api/saude', $this->comToken())->assertOk();

        $nomes = collect($r->json('verificacoes'))->pluck('nome')->all();
        foreach (['banco', 'migracoes', 'armazenamento', 'diretorios', 'disco', 'memoria_php', 'memoria_servidor', 'carga', 'cache', 'fila', 'agendador', 'php', 'configuracao'] as $esperada) {
            $this->assertContains($esperada, $nomes, "faltou a verificação {$esperada}");
        }
        foreach ($r->json('verificacoes') as $v) {
            $this->assertContains($v['status'], ['ok', 'alerta', 'falha']);
            $this->assertNotSame('', $v['mensagem'], "{$v['nome']} sem mensagem");
            $this->assertArrayHasKey('ms', $v);
        }
        $this->assertNotNull(collect($r->json('verificacoes'))->firstWhere('nome', 'banco')['latencia_ms']);
    }

    public function test_token_errado_ou_nao_configurado_nunca_expoe_o_detalhe(): void
    {
        $this->getJson('/api/saude', ['X-Saude-Token' => 'errado'])->assertOk()->assertJsonMissingPath('verificacoes');

        config(['saude.token' => '']);
        $this->getJson('/api/saude', ['X-Saude-Token' => ''])->assertOk()->assertJsonMissingPath('verificacoes');
        $this->getJson('/api/saude', $this->comToken())->assertOk()->assertJsonMissingPath('verificacoes');
    }

    public function test_resposta_nao_vaza_segredo_de_configuracao(): void
    {
        $corpo = $this->getJson('/api/saude', $this->comToken())->assertOk()->getContent();

        $this->assertStringNotContainsString((string) config('app.key'), $corpo);
        $this->assertStringNotContainsString((string) config('database.connections.pgsql.password'), $corpo);
        $this->assertStringNotContainsString(self::TOKEN, $corpo);
    }

    public function test_falha_critica_responde_503(): void
    {
        $this->app->bind(VerificacaoSaude::class, fn () => new class extends VerificacaoSaude
        {
            protected function banco(): array
            {
                return ['status' => self::FALHA, 'mensagem' => 'Banco fora.'];
            }
        });

        $r = $this->getJson('/api/saude', $this->comToken())->assertStatus(503);

        $r->assertJsonPath('status', 'falha');
        $this->assertSame('falha', collect($r->json('verificacoes'))->firstWhere('nome', 'banco')['status']);
    }

    public function test_excecao_dentro_de_uma_verificacao_vira_falha_e_nao_derruba_o_endpoint(): void
    {
        $this->app->bind(VerificacaoSaude::class, fn () => new class extends VerificacaoSaude
        {
            protected function cache(): array
            {
                throw new \RuntimeException('cache explodiu');
            }
        });

        $r = $this->getJson('/api/saude', $this->comToken())->assertStatus(503);

        $cache = collect($r->json('verificacoes'))->firstWhere('nome', 'cache');
        $this->assertSame('falha', $cache['status']);
        $this->assertStringContainsString('cache explodiu', $cache['mensagem']);
        // As demais verificações continuaram rodando.
        $this->assertGreaterThan(5, count($r->json('verificacoes')));
    }

    public function test_alerta_responde_200_e_vira_503_no_modo_estrito(): void
    {
        $this->app->bind(VerificacaoSaude::class, fn () => new class extends VerificacaoSaude
        {
            protected function disco(): array
            {
                return ['status' => self::ALERTA, 'mensagem' => '10% livre.'];
            }
        });

        $this->getJson('/api/saude')->assertOk()->assertJsonPath('status', 'alerta');
        $this->getJson('/api/saude?estrito=1')->assertStatus(503)->assertJsonPath('status', 'alerta');
    }

    public function test_sem_batimento_do_agendador_vira_alerta(): void
    {
        Cache::forget(VerificacaoSaude::CHAVE_BATIMENTO);

        $r = $this->getJson('/api/saude', $this->comToken())->assertOk();

        $r->assertJsonPath('status', 'alerta');
        $agendador = collect($r->json('verificacoes'))->firstWhere('nome', 'agendador');
        $this->assertSame('alerta', $agendador['status']);
    }

    public function test_batimento_muito_atrasado_do_agendador_vira_falha(): void
    {
        Cache::put(VerificacaoSaude::CHAVE_BATIMENTO, now()->subMinutes(30)->getTimestamp(), 3600);

        $this->getJson('/api/saude')->assertStatus(503)->assertJsonPath('status', 'falha');
    }

    public function test_armazenamento_que_nao_grava_derruba_a_saude(): void
    {
        // Um arquivo no lugar de diretório: o disco não consegue escrever.
        config([
            'filesystems.disks.quebrado' => ['driver' => 'local', 'root' => __FILE__.'/nao-da'],
            'filesystems.default' => 'quebrado',
        ]);

        $r = $this->getJson('/api/saude', $this->comToken())->assertStatus(503);

        $this->assertSame('falha', collect($r->json('verificacoes'))->firstWhere('nome', 'armazenamento')['status']);
    }

    /** @return array{0: string, 1: string} [status, mensagem] da memória do servidor com um /proc/meminfo de exemplo. */
    private function memoriaComMeminfo(string $conteudo): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'meminfo');
        file_put_contents($arquivo, $conteudo);

        $saude = new class($arquivo) extends VerificacaoSaude
        {
            public function __construct(private string $arquivo) {}

            protected function caminhoMeminfo(): string
            {
                return $this->arquivo;
            }

            public function memoria(): array
            {
                return $this->memoriaServidor();
            }
        };
        $r = $saude->memoria();
        @unlink($arquivo);

        return [$r['status'], $r['mensagem']];
    }

    public function test_memoria_do_servidor_ok_alerta_e_falha_pelo_percentual_disponivel(): void
    {
        $info = fn (int $total, int $disponivel, int $swapTotal = 0, int $swapLivre = 0) => implode("\n", [
            "MemTotal:       {$total} kB", 'MemFree:         100000 kB', "MemAvailable:   {$disponivel} kB",
            "SwapTotal:      {$swapTotal} kB", "SwapFree:       {$swapLivre} kB",
        ]);

        [$ok] = $this->memoriaComMeminfo($info(4000000, 2000000));          // 50% disponível
        [$alerta, $msg] = $this->memoriaComMeminfo($info(4000000, 480000)); // 12%
        [$falha] = $this->memoriaComMeminfo($info(4000000, 120000));        // 3%
        [$swap] = $this->memoriaComMeminfo($info(4000000, 2000000, 2000000, 400000)); // RAM ok, swap em 80%

        $this->assertSame('ok', $ok);
        $this->assertSame('alerta', $alerta);
        $this->assertStringContainsString('12%', $msg);
        $this->assertSame('falha', $falha);
        $this->assertSame('alerta', $swap, 'swap pesado também avisa');
    }

    public function test_job_parado_na_fila_vira_alerta(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp(),
        ]);

        $r = $this->getJson('/api/saude', $this->comToken())->assertOk();

        $r->assertJsonPath('status', 'alerta');
        $this->assertSame('alerta', collect($r->json('verificacoes'))->firstWhere('nome', 'fila')['status']);
    }
}
