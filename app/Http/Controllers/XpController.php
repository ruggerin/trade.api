<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Visita;
use App\Support\Xp\ParametrosXp;
use App\Support\Xp\PontuacaoPromotorXp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A régua de XP vista pelo promotor (docs/88 §12): "Minha pontuação" (Hoje e Semana), ranking da
 * equipe e o detalhe da pontuação de uma visita. Em modo prévia (`XP_VISIVEL_PROMOTOR` desligado)
 * o promotor não vê nada; ADMIN/GESTOR veem sempre, pra calibrar.
 */
class XpController extends Controller
{
    public function eu(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirVisivel($request);
        $periodo = $request->query('periodo') === 'semana' ? 'semana' : 'hoje';

        return response()->json([
            'pontuacao' => $periodo === 'semana'
                ? PontuacaoPromotorXp::semana($usuario->empresa, $usuario)
                : PontuacaoPromotorXp::hoje($usuario->empresa, $usuario),
        ]);
    }

    public function ranking(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirVisivel($request);
        abort_unless(ParametrosXp::rankingHabilitado($usuario->empresa), 403, 'A liga semanal está desligada nesta empresa.');

        return response()->json([
            'ranking' => PontuacaoPromotorXp::ranking($usuario->empresa, $usuario, $request->query('semana') === 'passada' ? 'passada' : 'atual'),
        ]);
    }

    public function visita(Request $request, Visita $visita): JsonResponse
    {
        $usuario = $request->user();
        if ($usuario->user_type === UserType::PROMOTOR) {
            abort_if($visita->usuario_id !== $usuario->id, 403, 'Você não tem acesso a esta visita.');
            $this->exigirVisivel($request);
        }

        return response()->json(['pontuacao' => PontuacaoPromotorXp::visita($usuario->empresa, $visita)]);
    }

    private function exigirVisivel(Request $request): void
    {
        $usuario = $request->user();
        if ($usuario->user_type === UserType::PROMOTOR && ! ParametrosXp::visivelPromotor($usuario->empresa)) {
            abort(403, 'A pontuação ainda não foi liberada pela sua empresa.');
        }
    }
}
