<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Usuario;
use App\Support\Fuso;
use App\Support\RotaDoDia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rota do dia — por onde o promotor passou num dia (docs/48-ROTA-DO-DIA.md). Atrás da permissão
 * própria `rastreamento.trajeto` (separada do mapa ao vivo).
 */
class RotaDoDiaController extends Controller
{
    /**
     * Promotores ativos pro seletor da tela — rota própria porque GET /usuarios exige
     * usuarios.gerenciar, que quem só vê rotas não tem (mesmo raciocínio de
     * PlanoAcaoController::responsaveis).
     */
    public function promotores(): JsonResponse
    {
        return response()->json([
            'promotores' => Usuario::query()
                ->where('user_type', UserType::PROMOTOR)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get()
                ->map(fn (Usuario $u) => [
                    'id' => $u->uuid,
                    'nome' => $u->nome,
                    'foto_url' => $u->foto_path ? url("/api/usuarios/{$u->uuid}/foto") : null,
                ])
                ->values(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'usuario_uuid' => ['required', 'uuid'],
            'data' => ['required', 'date_format:Y-m-d'],
        ]);

        // Global scope de empresa: promotor de outra empresa cai no 404.
        $promotor = Usuario::query()
            ->where('uuid', $dados['usuario_uuid'])
            ->where('user_type', UserType::PROMOTOR)
            ->firstOrFail();

        return response()->json([
            'promotor' => [
                'id' => $promotor->uuid,
                'nome' => $promotor->nome,
                'foto_url' => $promotor->foto_path ? url("/api/usuarios/{$promotor->uuid}/foto") : null,
            ],
            // "Dia" = meia-noite a meia-noite no fuso da EMPRESA (docs/50 §4.3) — não de quem olha
            // nem do servidor: o histórico é UTC e o corte precisa ser o mesmo pra todo gestor.
            ...RotaDoDia::montar($promotor, Carbon::parse($dados['data'], Fuso::daEmpresa($promotor->empresa))),
        ]);
    }
}
