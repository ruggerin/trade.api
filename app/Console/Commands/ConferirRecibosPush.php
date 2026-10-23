<?php

namespace App\Console\Commands;

use App\Support\Push;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recibos do push (docs/77 §3.2): a Expo só sabe se a mensagem chegou ao FCM uns minutos depois
 * do envio. Confere os tickets com 15+ min e apaga o token dos aparelhos que voltarem
 * DeviceNotRegistered (app desinstalado). A Expo guarda recibo por 24h — mais velho que isso sai.
 */
class ConferirRecibosPush extends Command
{
    protected $signature = 'push:conferir-recibos';

    protected $description = 'Confere os recibos do push da Expo e apaga tokens de aparelhos que desinstalaram o app';

    /** Limite da Expo por requisição de recibos. */
    private const LOTE = 1000;

    public function handle(): int
    {
        DB::table('push_recibos')->where('created_at', '<', now()->subDay())->delete();

        $pendentes = DB::table('push_recibos')
            ->whereNull('conferido_em')
            ->where('created_at', '<=', now()->subMinutes(15))
            ->orderBy('id')
            ->get(['id', 'dispositivo_id', 'ticket_id']);

        $esquecidos = 0;
        foreach ($pendentes->chunk(self::LOTE) as $lote) {
            try {
                $recibos = Push::http()
                    ->post(Push::URL_RECIBOS, ['ids' => $lote->pluck('ticket_id')->values()->all()])
                    ->throw()
                    ->json('data') ?? [];
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($lote as $linha) {
                if (($recibos[$linha->ticket_id]['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    Push::esquecerToken($linha->dispositivo_id);
                    $esquecidos++;
                }
            }
            DB::table('push_recibos')->whereIn('id', $lote->pluck('id'))->update(['conferido_em' => now()]);
        }

        $this->info("{$pendentes->count()} recibo(s) conferido(s), {$esquecidos} token(s) apagado(s).");

        return self::SUCCESS;
    }
}
