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
        // Atrás de nginx (host + o nginx dentro do próprio container) que sempre manda
        // X-Forwarded-Proto — sem isso, Request::isSecure() nunca vê "https" (a conexão real
        // php-fpm↔nginx interna é HTTP simples), e toda URL absoluta gerada pelo Laravel
        // (url()/route(), ex.: foto_url, contrato arquivo) sai como http:// mesmo servida atrás
        // de HTTPS — no cliente isso é bloqueado como mixed content ("recurso não carrega").
        // '*' é seguro aqui: a app só é alcançável via os nossos próprios nginx (porta do
        // container publicada só em 127.0.0.1), não há proxy de terceiros na frente.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'user_type' => \App\Http\Middleware\EnsureUserType::class,
            'permissao' => \App\Http\Middleware\EnsurePermissao::class,
            'licenca' => \App\Http\Middleware\EnsureLicencaValida::class,
            'canal.mobile' => \App\Http\Middleware\EnsureCanalMobile::class,
            'assinatura' => \App\Http\Middleware\EnsureAssinaturaAparelho::class,
            'xp' => \App\Http\Middleware\EnsureControleXp::class,
        ]);

        // App é só API, sem tela de login web — sem isso, uma request sem header Accept
        // correto faz o AuthenticationException tentar redirecionar pra uma rota `login` que
        // não existe (`route('login')`), virando 500 em vez de 401.
        $middleware->redirectGuestsTo(fn () => null);

        // Uso do dia por usuário/app pra medir adesão (docs/52) — roda depois da resposta, quando
        // o auth:sanctum da rota já identificou o usuário.
        $middleware->api(append: [\App\Http\Middleware\RegistrarAcessoDiario::class]);

        // Versão mínima do app (docs/67 Fase 2) — antes de tudo, inclusive do login.
        $middleware->api(prepend: [\App\Http\Middleware\EnsureVersaoApp::class]);

        // Versão da API em toda resposta (inclusive no 426 acima e nos erros).
        $middleware->api(prepend: [\App\Http\Middleware\AdicionarVersaoApi::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Garante resposta em JSON pra qualquer exceção em /api/*, independente do header
        // Accept enviado pelo cliente.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        // Sessão revogada com motivo (docs/67 §4.5): o 401 diz por que caiu, pro app mostrar
        // "Sua conta entrou em outro aparelho (Moto G, 14:32)" na tela de login.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            $encerrada = $request->attributes->get(\App\Support\SessaoApp::ATRIBUTO_SESSAO_ENCERRADA);
            if ($encerrada && $request->is('api/*')) {
                return new JsonResponse([
                    'message' => \App\Support\SessaoApp::mensagem($encerrada['motivo'], $encerrada['detalhe']),
                    'motivo' => $encerrada['motivo'],
                ], 401);
            }
        });

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

        // Sentry (homologação): reporta as exceções não tratadas. Sem SENTRY_LARAVEL_DSN no .env o
        // SDK fica desligado e isto não faz nada (ambiente local e testes). O que o Laravel já não
        // reporta (validação, 401/403, 404) continua de fora. Ver docs/70-SENTRY-HOMOLOGACAO.md.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
