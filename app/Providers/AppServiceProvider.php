<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
    }
}
