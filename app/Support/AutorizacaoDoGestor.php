<?php

namespace App\Support;

use App\Enums\Permissao;
use App\Enums\UserType;
use App\Models\AutorizacaoGestor;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Confere, no aparelho do promotor, a autorização de um gestor (docs/15 §12): o código de 6 dígitos
 * gerado no admin (preferido — o gestor não digita a senha no aparelho de outra pessoa) ou o
 * e-mail+senha dele. Vale ADMIN, ou GESTOR com `visitas.intervir`. Limitado a 5 tentativas por
 * promotor e por ação a cada 15 min. Usada no cancelamento de visita travada e na visita fora da
 * agenda (docs/74).
 */
final class AutorizacaoDoGestor
{
    /** Regras de validação do pedido — `codigo` ou `email`+`senha`. */
    public static function regras(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'size:6', 'required_without_all:email,senha'],
            'email' => ['nullable', 'string', 'email', 'required_without:codigo'],
            'senha' => ['nullable', 'string', 'required_without:codigo'],
        ];
    }

    /**
     * O gestor que autorizou, ou a resposta de erro (403 negado, 429 tentativas demais). O código só
     * é marcado como usado quando a autorização passa.
     *
     * @param  array{codigo?: ?string, email?: ?string, senha?: ?string}  $dados
     */
    public static function conferir(Usuario $promotor, array $dados, string $acao): Usuario|JsonResponse
    {
        $chave = $acao.':'.$promotor->id;
        if (RateLimiter::tooManyAttempts($chave, 5)) {
            return response()->json(['message' => 'Muitas tentativas. Aguarde alguns minutos antes de tentar de novo.'], 429);
        }

        $autorizacao = null;
        if (! empty($dados['codigo'])) {
            $autorizacao = AutorizacaoGestor::valido($promotor->empresa_id, $dados['codigo'])->first();
            $supervisor = $autorizacao?->usuario;
            // Gerar o código já exigiu a permissão (middleware da rota) — aqui só reconfirma que
            // a conta continua ativa no intervalo (código vive até 10 min).
            $autorizado = $autorizacao !== null && $supervisor?->ativo === true;
            $mensagemNegada = 'Autorização negada. Confira o código.';
        } else {
            $supervisor = Usuario::withoutGlobalScopes()
                ->where('email', $dados['email'] ?? '')
                ->where('empresa_id', $promotor->empresa_id)
                ->where('ativo', true)
                ->whereIn('user_type', [UserType::ADMIN->value, UserType::GESTOR->value])
                ->first();

            $autorizado = $supervisor
                && Hash::check($dados['senha'] ?? '', $supervisor->senha_hash)
                && ($supervisor->user_type === UserType::ADMIN || ($supervisor->perfil?->tem(Permissao::VISITAS_INTERVIR) ?? false));
            // Mensagem única de propósito: não diz se o e-mail existe nem se faltou permissão.
            $mensagemNegada = 'Autorização negada. Confira o e-mail e a senha do gestor.';
        }

        if (! $autorizado) {
            RateLimiter::hit($chave, 15 * 60);

            return response()->json(['message' => $mensagemNegada], 403);
        }

        RateLimiter::clear($chave);
        $autorizacao?->update(['usado_em' => now()]);

        return $supervisor;
    }
}
