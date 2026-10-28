<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\SessaoApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Documentação da API (dedoc/scramble, /docs/api). Sem este gate o Scramble só abre com
        // APP_ENV=local — em qualquer outro ambiente dava 403 pra todo mundo. Liga por ambiente
        // (API_DOCS_PUBLICA=true), desligado por padrão: aberto no servidor de teste, fechado em
        // produção. Parâmetro anulável: a página é pública (não há sessão web nesta API).
        Gate::define('viewApiDocs', fn ($usuario = null): bool => (bool) config('scramble.publica'));

        // Webhooks da API de integração (docs/96 Fase 4): o aviso entra na fila na mesma transação
        // do fato; o integracoes:entregar-webhooks entrega. Sem webhook na empresa, não faz nada.
        \App\Models\Visita::updated(function (\App\Models\Visita $visita): void {
            if ($visita->wasChanged('status') && in_array($visita->status, [\App\Enums\StatusVisita::FINALIZADA, \App\Enums\StatusVisita::CANCELADA], true)) {
                $evento = $visita->status === \App\Enums\StatusVisita::FINALIZADA ? 'visita.finalizada' : 'visita.cancelada';
                \App\Support\Webhooks::disparar($visita->empresa_id, $evento, fn () => \App\Support\Webhooks::dadosVisita($visita));
            }
        });
        \App\Models\VisitaRegistro::created(function (\App\Models\VisitaRegistro $registro): void {
            $empresaId = \App\Models\Visita::withoutGlobalScopes()->whereKey($registro->visita_id)->value('empresa_id');
            if ($empresaId === null) {
                return;
            }
            $dados = fn () => \App\Support\Webhooks::dadosRegistro($registro);
            \App\Support\Webhooks::disparar($empresaId, 'registro.criado', $dados);
            if ($registro->ruptura) {
                \App\Support\Webhooks::disparar($empresaId, 'ruptura.registrada', $dados);
            }
        });
        \App\Models\VisitaRegistro::updated(function (\App\Models\VisitaRegistro $registro): void {
            if ($registro->ruptura && $registro->wasChanged('alerta_resolvido_em') && $registro->alerta_resolvido_em !== null) {
                $empresaId = \App\Models\Visita::withoutGlobalScopes()->whereKey($registro->visita_id)->value('empresa_id');
                if ($empresaId !== null) {
                    \App\Support\Webhooks::disparar($empresaId, 'ruptura.resolvida', fn () => \App\Support\Webhooks::dadosRegistro($registro));
                }
            }
        });

        // Tokens com canal/rotação (docs/67 §4.4) — reaproveitar token rotacionado derruba as
        // sessões mobile do usuário, ver SessaoApp::tokenAceito.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $valido): bool => SessaoApp::tokenAceito($token, $valido),
        );

        // Força bruta e credential stuffing no login (docs/67 §2, brecha B8): 5 tentativas por
        // minuto por e-mail+IP, e 30 por IP no total (várias contas testadas da mesma origem).
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('email:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('ip:'.$request->ip()),
        ]);
    }
}
