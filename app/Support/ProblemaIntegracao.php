<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Formato único de erro da API de integração (docs/96 §7.3, RFC 9457 "problem details"), em
 * português como o resto da API: estrutura em inglês `{ type, title, status, detail, errors? }`, texto em português. As rotas do app e
 * do admin continuam com o `{ message }` de sempre.
 */
class ProblemaIntegracao
{
    private const TITULOS = [
        400 => 'Requisição inválida',
        401 => 'Token inválido',
        403 => 'Acesso negado',
        404 => 'Não encontrado',
        405 => 'Método não permitido',
        409 => 'Conflito',
        422 => 'Dados inválidos',
        429 => 'Limite de requisições atingido',
    ];

    /** @param  array<string, list<string>>|null  $erros */
    public static function resposta(int $status, string $detalhe, ?array $erros = null, array $headers = []): JsonResponse
    {
        $titulo = self::TITULOS[$status] ?? ($status >= 500 ? 'Erro interno' : 'Erro');

        return new JsonResponse(array_filter([
            'type' => url('/docs/integracao').'#erros',
            'title' => $titulo,
            'status' => $status,
            'detail' => $detalhe,
            'errors' => $erros,
        ], fn ($v) => $v !== null), $status, $headers + ['Content-Type' => 'application/problem+json']);
    }
}
