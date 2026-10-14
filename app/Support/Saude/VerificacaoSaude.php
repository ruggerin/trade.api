<?php

namespace App\Support\Saude;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Verificações do endpoint de saúde (docs/71): cada uma testa de verdade (conecta, grava, lê,
 * mede) em vez de só responder 200. Cada resultado é `ok`, `alerta` (funciona, mas merece olhar)
 * ou `falha` (o servidor não está em condição de atender). Uma verificação que lança exceção vira
 * `falha` — nunca derruba o endpoint.
 */
class VerificacaoSaude
{
    public const OK = 'ok';

    public const ALERTA = 'alerta';

    public const FALHA = 'falha';

    /** Chave do cache em que o agendador deixa o batimento (routes/console.php). */
    public const CHAVE_BATIMENTO = 'saude:agendador';

    /**
     * @return array{status: string, verificacoes: list<array<string, mixed>>}
     */
    public function executar(): array
    {
        $resultados = [];
        foreach ($this->verificacoes() as $nome => $verificacao) {
            $inicio = hrtime(true);
            try {
                $r = $verificacao();
            } catch (\Throwable $e) {
                $r = ['status' => self::FALHA, 'mensagem' => 'Erro ao verificar: '.Str::limit($e->getMessage(), 160)];
            }
            $resultados[] = ['nome' => $nome] + $r + ['ms' => (int) round((hrtime(true) - $inicio) / 1_000_000)];
        }

        return ['status' => $this->consolidar($resultados), 'verificacoes' => $resultados];
    }

    /** @param list<array<string, mixed>> $resultados */
    public function consolidar(array $resultados): string
    {
        $status = array_column($resultados, 'status');

        return in_array(self::FALHA, $status, true) ? self::FALHA : (in_array(self::ALERTA, $status, true) ? self::ALERTA : self::OK);
    }

    /** @return array<string, callable(): array<string, mixed>> */
    protected function verificacoes(): array
    {
        return [
            'banco' => fn () => $this->banco(),
            'migracoes' => fn () => $this->migracoes(),
            'armazenamento' => fn () => $this->armazenamento(),
            'diretorios' => fn () => $this->diretorios(),
            'disco' => fn () => $this->disco(),
            'memoria_php' => fn () => $this->memoriaPhp(),
            'memoria_servidor' => fn () => $this->memoriaServidor(),
            'carga' => fn () => $this->carga(),
            'cache' => fn () => $this->cache(),
            'fila' => fn () => $this->fila(),
            'agendador' => fn () => $this->agendador(),
            'php' => fn () => $this->php(),
            'configuracao' => fn () => $this->configuracao(),
        ];
    }

    // ---- banco ----

    protected function banco(): array
    {
        $inicio = hrtime(true);
        DB::select('select 1');
        $ms = (int) round((hrtime(true) - $inicio) / 1_000_000);

        $status = self::OK;
        $mensagem = "Respondeu em {$ms} ms.";
        if ($ms >= config('saude.banco_latencia_falha_ms')) {
            $status = self::FALHA;
            $mensagem = "Banco lento: {$ms} ms (limite ".config('saude.banco_latencia_falha_ms').' ms).';
        } elseif ($ms >= config('saude.banco_latencia_alerta_ms')) {
            $status = self::ALERTA;
            $mensagem = "Banco com latência alta: {$ms} ms.";
        }

        $extra = [];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $c = DB::selectOne('select count(*) as em_uso, current_setting(\'max_connections\')::int as maximo from pg_stat_activity');
            $pct = $c->maximo > 0 ? (int) round($c->em_uso / $c->maximo * 100) : 0;
            $extra = ['conexoes' => "{$c->em_uso}/{$c->maximo}"];
            if ($pct >= config('saude.banco_conexoes_alerta_pct') && $status === self::OK) {
                $status = self::ALERTA;
                $mensagem = "Conexões do banco em {$pct}% do máximo ({$c->em_uso}/{$c->maximo}).";
            }
        }

        return ['status' => $status, 'mensagem' => $mensagem, 'latencia_ms' => $ms] + $extra;
    }

    protected function migracoes(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['status' => self::FALHA, 'mensagem' => 'Tabela de migrações não existe — banco não migrado.'];
        }

        $arquivos = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
        $rodadas = $migrator->getRepository()->getRan();
        $pendentes = array_values(array_diff($arquivos, $rodadas));

        return $pendentes === []
            ? ['status' => self::OK, 'mensagem' => 'Todas as migrações aplicadas.']
            : ['status' => self::ALERTA, 'mensagem' => count($pendentes).' migração(ões) pendente(s): '.Str::limit(implode(', ', array_slice($pendentes, 0, 3)), 140)];
    }

    // ---- armazenamento ----

    /** Grava, lê e apaga um arquivo em cada disco que o Laravel usa pra funcionar. */
    protected function armazenamento(): array
    {
        $discos = array_unique([config('filesystems.default'), 'local']);
        $problemas = [];

        foreach ($discos as $disco) {
            $arquivo = '.saude-'.Str::random(12);
            try {
                $armazenamento = Storage::disk($disco);
                $armazenamento->put($arquivo, 'ok');
                $leu = $armazenamento->get($arquivo);
                $armazenamento->delete($arquivo);
                if ($leu !== 'ok') {
                    $problemas[] = "{$disco}: leitura divergente";
                }
            } catch (\Throwable $e) {
                $problemas[] = "{$disco}: ".Str::limit($e->getMessage(), 80);
            }
        }

        return $problemas === []
            ? ['status' => self::OK, 'mensagem' => 'Gravação e leitura OK em: '.implode(', ', $discos).'.']
            : ['status' => self::FALHA, 'mensagem' => 'Falha no armazenamento — '.implode('; ', $problemas)];
    }

    /** Diretórios que o framework precisa escrever (cache, views, sessões, logs). */
    protected function diretorios(): array
    {
        $dirs = [
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $ruins = [];
        foreach ($dirs as $rotulo => $caminho) {
            if (! File::isDirectory($caminho) || ! File::isWritable($caminho)) {
                $ruins[] = $rotulo;
            }
        }

        return $ruins === []
            ? ['status' => self::OK, 'mensagem' => 'Diretórios do framework graváveis.']
            : ['status' => self::FALHA, 'mensagem' => 'Sem escrita em: '.implode(', ', $ruins).'.'];
    }

    protected function disco(): array
    {
        $caminho = storage_path();
        $livre = @disk_free_space($caminho);
        $total = @disk_total_space($caminho);
        if ($livre === false || ! $total) {
            return ['status' => self::ALERTA, 'mensagem' => 'Não foi possível medir o espaço em disco.'];
        }

        $pct = (int) round($livre / $total * 100);
        $gb = round($livre / 1073741824, 1);
        $gbLivre = $livre / 1073741824;
        // Percentual sozinho engana: 6% de um disco grande ainda são dezenas de GB. Com folga absoluta
        // (SAUDE_DISCO_OK_ACIMA_GB) fica ok; abaixo de 1 GB é falha sempre.
        if ($gbLivre >= (float) config('saude.disco_ok_acima_gb')) {
            $status = self::OK;
        } elseif ($pct <= config('saude.disco_livre_falha_pct') || $gbLivre < 1) {
            $status = self::FALHA;
        } elseif ($pct <= config('saude.disco_livre_alerta_pct') || $gbLivre < 5) {
            $status = self::ALERTA;
        } else {
            $status = self::OK;
        }

        return [
            'status' => $status,
            'mensagem' => "{$pct}% livre ({$gb} GB).",
            'livre_pct' => $pct,
        ];
    }

    // ---- memória e carga ----

    protected function memoriaPhp(): array
    {
        $limite = $this->bytes((string) ini_get('memory_limit'));
        $usado = memory_get_peak_usage(true);
        if ($limite <= 0) {
            return ['status' => self::OK, 'mensagem' => 'memory_limit sem teto; pico desta requisição: '.$this->mb($usado).' MB.'];
        }

        $pct = (int) round($usado / $limite * 100);
        $status = $pct >= config('saude.php_memoria_alerta_pct') ? self::ALERTA : self::OK;

        return [
            'status' => $status,
            'mensagem' => "Pico de {$this->mb($usado)} MB de {$this->mb($limite)} MB ({$pct}%).",
            'uso_pct' => $pct,
        ];
    }

    /** RAM e swap do servidor (Linux, via /proc/meminfo). Em outro SO, informativo. */
    protected function memoriaServidor(): array
    {
        $arquivo = $this->caminhoMeminfo();
        if (! is_readable($arquivo)) {
            return ['status' => self::OK, 'mensagem' => 'Não medido neste sistema operacional.'];
        }

        $info = [];
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES) ?: [] as $linha) {
            if (preg_match('/^(\w+):\s+(\d+)/', $linha, $m)) {
                $info[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $total = $info['MemTotal'] ?? 0;
        $disponivel = $info['MemAvailable'] ?? ($info['MemFree'] ?? 0);
        if ($total <= 0) {
            return ['status' => self::ALERTA, 'mensagem' => 'Não foi possível ler a memória do servidor.'];
        }

        $pct = (int) round($disponivel / $total * 100);
        $swapTotal = $info['SwapTotal'] ?? 0;
        $swapUsado = $swapTotal > 0 ? $swapTotal - ($info['SwapFree'] ?? 0) : 0;
        $swapPct = $swapTotal > 0 ? (int) round($swapUsado / $swapTotal * 100) : 0;

        $status = $pct <= config('saude.memoria_disponivel_falha_pct') ? self::FALHA
            : (($pct <= config('saude.memoria_disponivel_alerta_pct') || $swapPct >= 50) ? self::ALERTA : self::OK);

        return [
            'status' => $status,
            'mensagem' => "{$pct}% disponível ({$this->mb($disponivel)} de {$this->mb($total)} MB)".($swapTotal > 0 ? "; swap em {$swapPct}%." : '.'),
            'disponivel_pct' => $pct,
        ];
    }

    /** Separado pra o teste poder apontar pra um arquivo de exemplo (no Windows não há /proc). */
    protected function caminhoMeminfo(): string
    {
        return '/proc/meminfo';
    }

    protected function carga(): array
    {
        if (! function_exists('sys_getloadavg')) {
            return ['status' => self::OK, 'mensagem' => 'Não medido neste sistema operacional.'];
        }
        $carga = @sys_getloadavg();
        if (! is_array($carga)) {
            return ['status' => self::OK, 'mensagem' => 'Não medido neste sistema operacional.'];
        }

        $nucleos = 1;
        if (is_readable('/proc/cpuinfo')) {
            $nucleos = max(1, substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor"));
        }
        $limite = $nucleos * (float) config('saude.carga_alerta_por_nucleo');
        $um = round($carga[0], 2);

        return [
            'status' => $um >= $limite ? self::ALERTA : self::OK,
            'mensagem' => "Carga {$um} (1 min) em {$nucleos} núcleo(s); alerta a partir de ".round($limite, 1).'.',
        ];
    }

    // ---- cache, fila, agendador ----

    protected function cache(): array
    {
        $chave = 'saude:teste:'.Str::random(8);
        Cache::put($chave, 'ok', 30);
        $leu = Cache::get($chave);
        Cache::forget($chave);

        return $leu === 'ok'
            ? ['status' => self::OK, 'mensagem' => 'Gravação e leitura OK ('.config('cache.default').').']
            : ['status' => self::FALHA, 'mensagem' => 'O cache gravou mas não devolveu o valor ('.config('cache.default').').'];
    }

    protected function fila(): array
    {
        if (config('queue.default') !== 'database') {
            return ['status' => self::OK, 'mensagem' => 'Fila "'.config('queue.default').'" — não verificada em detalhe.'];
        }
        if (! Schema::hasTable('jobs')) {
            return ['status' => self::FALHA, 'mensagem' => 'Tabela de jobs não existe.'];
        }

        $limiteMin = (int) config('saude.fila_parado_alerta_min');
        $parados = DB::table('jobs')->where('available_at', '<=', now()->subMinutes($limiteMin)->getTimestamp())->count();
        $pendentes = DB::table('jobs')->count();
        $falhas = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : 0;

        if ($parados > 0) {
            return ['status' => self::ALERTA, 'mensagem' => "{$parados} job(s) parado(s) há mais de {$limiteMin} min — o worker da fila está rodando?", 'pendentes' => $pendentes];
        }
        if ($falhas >= (int) config('saude.fila_falhas_24h_alerta')) {
            return ['status' => self::ALERTA, 'mensagem' => "{$falhas} job(s) falharam nas últimas 24 h.", 'pendentes' => $pendentes];
        }

        return ['status' => self::OK, 'mensagem' => "Fila em dia ({$pendentes} pendente(s)).", 'pendentes' => $pendentes];
    }

    /** O agendador grava um batimento por minuto (routes/console.php). Sem batimento, o cron não roda. */
    protected function agendador(): array
    {
        $ultimo = Cache::get(self::CHAVE_BATIMENTO);
        if ($ultimo === null) {
            return ['status' => self::ALERTA, 'mensagem' => 'Sem batimento do agendador — o cron de `schedule:run` está configurado?'];
        }

        $minutos = (int) floor((now()->getTimestamp() - (int) $ultimo) / 60);
        $alerta = (int) config('saude.agendador_atraso_alerta_min');
        $status = $minutos >= $alerta * 2 ? self::FALHA : ($minutos >= $alerta ? self::ALERTA : self::OK);

        return [
            'status' => $status,
            'mensagem' => $minutos <= 0 ? 'Último batimento há menos de 1 min.' : "Último batimento há {$minutos} min.",
            'atraso_min' => $minutos,
        ];
    }

    // ---- ambiente ----

    protected function php(): array
    {
        $faltando = array_values(array_filter(
            ['pdo', 'pdo_pgsql', 'mbstring', 'openssl', 'json', 'ctype', 'xml', 'zlib', 'fileinfo'],
            fn (string $ext) => ! extension_loaded($ext),
        ));

        return $faltando === []
            ? ['status' => self::OK, 'mensagem' => 'PHP '.PHP_VERSION.' com as extensões necessárias.']
            : ['status' => self::FALHA, 'mensagem' => 'Extensões PHP ausentes: '.implode(', ', $faltando).'.'];
    }

    protected function configuracao(): array
    {
        $avisos = [];
        if (! config('app.key')) {
            return ['status' => self::FALHA, 'mensagem' => 'APP_KEY não definida.'];
        }
        if (app()->environment('production') && config('app.debug')) {
            $avisos[] = 'APP_DEBUG ligado em produção';
        }
        if (! app()->environment(['local', 'testing']) && ! config('sentry.dsn')) {
            $avisos[] = 'Sentry sem DSN (erros não estão sendo reportados)';
        }

        return $avisos === []
            ? ['status' => self::OK, 'mensagem' => 'Ambiente "'.config('app.env').'" configurado.']
            : ['status' => self::ALERTA, 'mensagem' => implode('; ', $avisos).'.'];
    }

    // ---- utilitários ----

    protected function mb(int|float $bytes): int
    {
        return (int) round($bytes / 1048576);
    }

    /** "512M", "2G", "-1" → bytes (0 = sem limite). */
    protected function bytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '' || $valor === '-1') {
            return 0;
        }
        $n = (int) $valor;

        return match (strtolower(substr($valor, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
