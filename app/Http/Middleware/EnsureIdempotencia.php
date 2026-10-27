<?php

namespace App\Http\Middleware;

use App\Models\Integracao;
use App\Support\ProblemaIntegracao;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` nas escritas da API de integração (docs/96 §7.2). Reenviar o mesmo lote com a
 * mesma chave (timeout, queda de rede) devolve a resposta guardada, com `Idempotency-Replayed: true`,
 * sem gravar de novo. A chave vale 24 h por integração (limpeza no `integracoes:limpar-requisicoes`).
 *
 * - Mesma chave com outro corpo: 422 (a chave identifica UMA operação).
 * - Mesma chave enquanto a primeira ainda está rodando: 409, tente de novo em instantes.
 * - Resposta 5xx não é guardada: o cliente pode reenviar com a mesma chave.
 * - Sem o cabeçalho, segue normal (o upsert por código do ERP já não duplica cadastro).
 */
class EnsureIdempotencia
{
    public const HORAS_VALIDADE = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $chave = $request->header('Idempotency-Key');
        $integracao = $request->user();
        if ($chave === null || $request->isMethodSafe() || ! $integracao instanceof Integracao) {
            return $next($request);
        }
        if ($chave === '' || mb_strlen($chave) > 100) {
            return ProblemaIntegracao::resposta(400, 'Idempotency-Key precisa ter de 1 a 100 caracteres.');
        }

        $assinatura = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $tabela = DB::table('integracao_idempotencia');
        $tabela->clone()->where('integracao_id', $integracao->id)->where('chave', $chave)
            ->where('created_at', '<', now()->subHours(self::HORAS_VALIDADE))->delete();

        // ON CONFLICT DO NOTHING: quem chega segundo com a mesma chave não aborta a transação (Postgres).
        $inserida = $tabela->clone()->insertOrIgnore(['integracao_id' => $integracao->id, 'chave' => $chave, 'assinatura' => $assinatura, 'created_at' => now()]);
        $registro = $tabela->clone()->where('integracao_id', $integracao->id)->where('chave', $chave)->first();
        if ($inserida === 0) {
            $anterior = $registro;

            return match (true) {
                $anterior === null => ProblemaIntegracao::resposta(409, 'Conflito na Idempotency-Key. Tente de novo.'),
                $anterior->assinatura !== $assinatura => ProblemaIntegracao::resposta(422, 'Esta Idempotency-Key já foi usada com outro conteúdo. Use uma chave nova para cada operação.'),
                $anterior->status === null => ProblemaIntegracao::resposta(409, 'A operação com esta Idempotency-Key ainda está sendo processada. Tente de novo em instantes.', null, ['Retry-After' => 5]),
                default => response((string) $anterior->resposta, (int) $anterior->status, [
                    'Content-Type' => 'application/json',
                    'Idempotency-Replayed' => 'true',
                ]),
            };
        }
        $id = $registro->id;

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            $tabela->clone()->where('id', $id)->delete();
        } else {
            $tabela->clone()->where('id', $id)->update(['status' => $response->getStatusCode(), 'resposta' => $response->getContent()]);
        }

        return $response;
    }
}
