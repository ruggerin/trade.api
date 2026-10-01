<?php

namespace App\Http\Middleware;

use App\Support\Adesao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca o uso do dia (docs/52-LOG-DE-ACESSO-E-ADESAO.md): a primeira requisição autenticada do
 * usuário no dia, por app, vira uma linha em `acessos_diarios`. Depois da resposta montada — a
 * autenticação já rodou e nada aqui atrasa a regra de negócio.
 *
 * Fica de fora a posição enviada em segundo plano (`PATCH /localizacao`): o app manda isso com a
 * tela apagada, não é o promotor usando o sistema.
 */
class RegistrarAcessoDiario
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $usuario = $request->user();
        if ($usuario && $response->getStatusCode() < 400 && ! ($request->isMethod('PATCH') && $request->is('api/localizacao'))) {
            try {
                Adesao::registrar($usuario, Adesao::appDaRequisicao($request, $usuario));
            } catch (\Throwable $e) {
                // Medição nunca derruba a requisição de verdade.
                report($e);
            }
        }

        return $response;
    }
}
