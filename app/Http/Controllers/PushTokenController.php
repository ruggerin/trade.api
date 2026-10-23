<?php

namespace App\Http\Controllers;

use App\Models\Dispositivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Token de push do aparelho da sessão atual — docs/77-PUSH-NOTIFICATIONS.md §3.2. O app manda
 * depois do login e a cada abertura (só quando muda) e apaga no logout. O aparelho vem do próprio
 * token Sanctum (`personal_access_tokens.dispositivo_id`), então não existe parâmetro de usuário.
 */
class PushTokenController extends Controller
{
    public function salvar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[.+\]$/'],
        ]);

        $dispositivo = $this->dispositivoAtual($request);
        abort_if($dispositivo === null, 409, 'Esta sessão não está presa a um aparelho — faça login de novo no app.');

        // Um token = um celular. Se outro login (outro usuário no mesmo aparelho, ou um vínculo
        // antigo) ainda tem esse token, tira de lá — senão o push de um promotor chega no celular
        // de outro.
        Dispositivo::where('push_token', $dados['token'])
            ->where('id', '!=', $dispositivo->id)
            ->update(['push_token' => null, 'push_token_em' => null]);

        $dispositivo->forceFill(['push_token' => $dados['token'], 'push_token_em' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function apagar(Request $request): Response
    {
        $this->dispositivoAtual($request)?->forceFill(['push_token' => null, 'push_token_em' => null])->save();

        return response()->noContent();
    }

    private function dispositivoAtual(Request $request): ?Dispositivo
    {
        $dispositivoId = $request->user()->currentAccessToken()?->dispositivo_id;

        return $dispositivoId ? Dispositivo::ativo()->find($dispositivoId) : null;
    }
}
