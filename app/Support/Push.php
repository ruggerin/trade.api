<?php

namespace App\Support;

use App\Models\Dispositivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Push notifications pelo serviço da Expo — docs/77-PUSH-NOTIFICATIONS.md §3.2.
 *
 * Destino = o aparelho ATIVO de cada usuário (`dispositivos.push_token`). O envio roda depois da
 * resposta (`defer`), no mesmo processo: o projeto não tem worker de fila em produção, e a
 * requisição de quem disparou (gestor comentando, ERP mandando pedido) não espera a Expo.
 *
 * LGPD (§3.3): título e corpo genéricos, só ids em `data` — nome, texto do comentário e valores
 * passam pelos servidores da Expo e do Google, então não vão no push; o app busca na API ao tocar.
 *
 * Falha no envio nunca derruba quem chamou: vai pro report() (Sentry) e o sino continua valendo.
 */
final class Push
{
    public const URL_ENVIO = 'https://exp.host/--/api/v2/push/send';

    public const URL_RECIBOS = 'https://exp.host/--/api/v2/push/getReceipts';

    /** Limite da Expo por requisição. */
    public const LOTE = 100;

    /** Canais Android criados pelo app (mobile/src/lib/push.ts) — o promotor silencia cada um. */
    public const CANAL_COMENTARIOS = 'comentarios';

    public const CANAL_PEDIDOS = 'pedidos';

    public const CANAL_AGENDA = 'agenda';

    /**
     * @param  iterable<int>  $usuarioIds
     * @param  array<string, mixed>  $data  só ids e o `tipo` que o app usa pra navegar
     */
    public static function paraUsuarios(iterable $usuarioIds, string $titulo, string $corpo, array $data, string $canal): void
    {
        if (! self::configurado()) {
            return;
        }

        $ids = collect($usuarioIds)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $dispositivos = Dispositivo::query()
            ->ativo()
            ->whereIn('usuario_id', $ids)
            ->whereNotNull('push_token')
            ->get(['id', 'push_token']);
        if ($dispositivos->isEmpty()) {
            return;
        }

        $mensagens = $dispositivos->map(fn (Dispositivo $d) => [
            'dispositivo_id' => $d->id,
            'to' => $d->push_token,
            'title' => $titulo,
            'body' => $corpo,
            'data' => $data,
            'channelId' => $canal,
            'sound' => 'default',
            'priority' => 'high',
        ])->all();

        defer(fn () => self::enviar($mensagens));
    }

    public static function configurado(): bool
    {
        return filled(config('services.expo.access_token'));
    }

    /**
     * Manda em lotes e trata a resposta de cada mensagem: ok → guarda o ticket pra conferir o
     * recibo depois; DeviceNotRegistered → o app foi desinstalado, apaga o token na hora.
     *
     * @param  list<array<string, mixed>>  $mensagens  cada uma com `dispositivo_id` (não vai pra Expo)
     */
    public static function enviar(array $mensagens): void
    {
        foreach (array_chunk($mensagens, self::LOTE) as $lote) {
            try {
                $resposta = self::http()->post(self::URL_ENVIO, array_map(
                    fn ($m) => array_diff_key($m, ['dispositivo_id' => true]),
                    $lote,
                ));
                $resposta->throw();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $agora = now();
            $tickets = [];
            foreach ($resposta->json('data') ?? [] as $i => $ticket) {
                $dispositivoId = $lote[$i]['dispositivo_id'] ?? null;
                if (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
                    $tickets[] = ['dispositivo_id' => $dispositivoId, 'ticket_id' => $ticket['id'], 'created_at' => $agora];
                } elseif (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    self::esquecerToken($dispositivoId);
                }
            }
            if ($tickets) {
                DB::table('push_recibos')->insert($tickets);
            }
        }
    }

    public static function esquecerToken(?int $dispositivoId): void
    {
        if ($dispositivoId !== null) {
            Dispositivo::whereKey($dispositivoId)->update(['push_token' => null, 'push_token_em' => null]);
        }
    }

    public static function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('services.expo.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }
}
