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
