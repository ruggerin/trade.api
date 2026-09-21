<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Usuario;
use App\Support\Rastreamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rastreamento em tempo real — ver docs/11-RASTREAMENTO-TEMPO-REAL.md. Só a posição mais recente
 * de cada promotor (3 colunas em `usuarios`), sem histórico.
 */
class LocalizacaoController extends Controller
{
    /**
     * O próprio promotor manda a posição dele — sempre grava em cima do usuário autenticado (não
     * existe parâmetro de usuário, então não precisa de checagem de ownership). Idempotente.
     */
    public function atualizar(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless($usuario->user_type === UserType::PROMOTOR, 403, 'Só o promotor envia localização.');
        abort_unless(
            $usuario->empresa && Rastreamento::habilitado($usuario->empresa),
            403,
            'O rastreamento não está habilitado para a sua empresa.',
        );

        $dados = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Momento em que o aparelho capturou a posição (não quando chegou aqui) — opcional.
            'capturado_em' => ['nullable', 'date'],
        ]);

        $capturadoEm = isset($dados['capturado_em']) ? now()->parse($dados['capturado_em']) : now();
        // Relógio do aparelho adiantado não pode fazer a posição parecer "do futuro".
        if ($capturadoEm->isFuture()) {
            $capturadoEm = now();
        }

        // Um envio atrasado (rede lenta, reenvio) nunca sobrescreve uma posição mais recente.
        if ($usuario->ultima_localizacao_em && $usuario->ultima_localizacao_em->gt($capturadoEm)) {
            return response()->json(status: 204);
        }

        $usuario->forceFill([
            'ultima_localizacao_latitude' => $dados['latitude'],
            'ultima_localizacao_longitude' => $dados['longitude'],
            'ultima_localizacao_em' => $capturadoEm,
        ])->save();

        return response()->json(status: 204);
    }

    /** Promotores da empresa que já compartilharam posição alguma vez (nunca-compartilhou não entra). */
    public function index(Request $request): JsonResponse
    {
        $limiteAtivo = now()->subMinutes(Rastreamento::JANELA_ATIVO_MINUTOS);

        $promotores = Usuario::query()
            ->where('user_type', UserType::PROMOTOR)
            ->where('ativo', true)
            ->whereNotNull('ultima_localizacao_em')
            ->orderBy('nome')
            ->get();

        return response()->json([
            'localizacoes' => $promotores->map(fn (Usuario $u) => [
                'id' => $u->uuid,
                'nome' => $u->nome,
                'foto_url' => $u->foto_path ? url("/api/usuarios/{$u->uuid}/foto") : null,
                'latitude' => $u->ultima_localizacao_latitude,
                'longitude' => $u->ultima_localizacao_longitude,
                'ultima_localizacao_em' => $u->ultima_localizacao_em,
                // Calculado na exibição, nunca gravado (doc 11 §3.1).
                'ativo_agora' => $u->ultima_localizacao_em->gte($limiteAtivo),
            ])->values(),
        ]);
    }
}
