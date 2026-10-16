<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carimba a versão da API (config app.versao) em toda resposta — o app guarda a última que viu,
 * pra o Perfil mostrar "API 2026.10.16-b3506b7" mesmo sem uma chamada própria.
 */
class AdicionarVersaoApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);
        $resposta->headers->set('X-Api-Version', (string) config('app.versao'));

        return $resposta;
    }
}
