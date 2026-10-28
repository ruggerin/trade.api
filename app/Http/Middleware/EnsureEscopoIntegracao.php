<?php

namespace App\Http\Middleware;

use App\Enums\EscopoIntegracao;
use App\Models\Integracao;
use App\Support\ProblemaIntegracao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escopo exigido pela rota de integração (docs/96 §5): `middleware('escopo:visitas:ler')`. Confere
 * os escopos atuais da integração (não a cópia no token), então editar as permissões na tela vale
 * na hora. Sem o escopo: 403 dizendo qual faltou.
 */
class EnsureEscopoIntegracao
{
    public function handle(Request $request, Closure $next, string $escopo): Response
    {
        $exigido = EscopoIntegracao::from($escopo);
        $integracao = $request->user();

        if (! $integracao instanceof Integracao || ! $integracao->temEscopo($exigido)) {
            return ProblemaIntegracao::resposta(403, "Este token não tem a permissão {$exigido->value}.");
        }

        return $next($request);
    }
}
