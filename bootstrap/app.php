<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user_type' => \App\Http\Middleware\EnsureUserType::class,
            'permissao' => \App\Http\Middleware\EnsurePermissao::class,
        ]);

        // App é só API, sem tela de login web — sem isso, uma request sem header Accept
        // correto faz o AuthenticationException tentar redirecionar pra uma rota `login` que
        // não existe (`route('login')`), virando 500 em vez de 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Garante resposta em JSON pra qualquer exceção em /api/*, independente do header
        // Accept enviado pelo cliente.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        // Toda entidade tenant-aware é endereçada só por uuid (route model binding e queries
        // manuais tipo Model::where('uuid', ...)) — mas a coluna é do tipo `uuid` nativo do
        // Postgres, que rejeita no próprio banco qualquer string que não seja um UUID
        // sintaticamente válido (SQLSTATE 22P02), antes do Eloquent ter a chance de dizer
        // "não encontrado". Sem isso, mandar um uuid mal formado em qualquer rota (path ou
        // query param) derruba com 500 em vez de 404 — não é sobre o registro não existir,
        // é sobre o valor nem ser um uuid de verdade, mas o efeito esperado pro cliente é o
        // mesmo dos dois casos.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($e->getCode() === '22P02' && ($request->is('api/*') || $request->expectsJson())) {
                return new JsonResponse(['message' => 'Recurso não encontrado.'], 404);
            }
        });
    })->create();
