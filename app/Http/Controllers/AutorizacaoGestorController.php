<?php

namespace App\Http\Controllers;

use App\Models\AutorizacaoGestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Código de autorização gerado no admin web — o gestor lê/dita por telefone pro promotor, que
 * digita no lugar de e-mail e senha em App\Http\Controllers\VisitaController::cancelarAutorizado.
 * Ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md §12. Rota sob middleware
 * permissao:visitas.intervir (ADMIN sempre; GESTOR só com a permissão no perfil) — quem gera o
 * código já é, ele mesmo, o autorizado; nenhuma checagem extra aqui.
 */
class AutorizacaoGestorController extends Controller
{
    private const MINUTOS_VALIDADE = 10;
    private const MAX_TENTATIVAS_CODIGO_UNICO = 5;

    public function store(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $codigo = $this->gerarCodigoUnico($usuario->empresa_id);

        $autorizacao = AutorizacaoGestor::create([
            'empresa_id' => $usuario->empresa_id,
            'usuario_id' => $usuario->id,
            'codigo' => $codigo,
            'expira_em' => now()->addMinutes(self::MINUTOS_VALIDADE),
        ]);

        return response()->json([
            'codigo' => $autorizacao->codigo,
            'expira_em' => $autorizacao->expira_em,
        ], 201);
    }

    /** 6 dígitos, sem colisão com outro código ainda válido da mesma empresa (retry raro na prática). */
    private function gerarCodigoUnico(int $empresaId): string
    {
        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_CODIGO_UNICO; $tentativa++) {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $emUso = AutorizacaoGestor::valido($empresaId, $codigo)->exists();
            if (! $emUso) {
                return $codigo;
            }
        }

        // Praticamente inalcançável (1 em 1.000.000 por tentativa) — última tentativa vale como está.
        return $codigo;
    }
}
