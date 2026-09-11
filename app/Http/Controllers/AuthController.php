<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Dispositivo;
use App\Models\Usuario;
use App\Models\UsuarioLoginLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credenciais = $request->validated();

        $usuario = Usuario::withoutGlobalScopes()
            ->where('email', $credenciais['email'])
            ->first();

        if (! $usuario || ! Hash::check($credenciais['senha'], $usuario->senha_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        if (! $usuario->ativo) {
            throw ValidationException::withMessages([
                'email' => ['Este usuário está desativado.'],
            ]);
        }

        // SUPERADMIN não pertence a empresa nenhuma (empresa_id null) — só usuário de empresa
        // cliente pode ser barrado por bloqueio de empresa. Ver EmpresaController::destroySuperadmin.
        if ($usuario->empresa_id !== null && ! $usuario->empresa->ativo) {
            throw ValidationException::withMessages([
                'email' => ['A empresa deste usuário está bloqueada. Fale com o suporte.'],
            ]);
        }

        // Trava de 1 sessão ativa por PROMOTOR: logar num aparelho novo derruba o antigo
        // automaticamente. Não se aplica a ADMIN/GESTOR/SUPERADMIN (admin web sem restrição).
        if ($usuario->user_type === UserType::PROMOTOR) {
            if (empty($credenciais['dispositivo_identificador'])) {
                throw ValidationException::withMessages([
                    'dispositivo_identificador' => ['Obrigatório para login do app mobile.'],
                ]);
            }

            // Revoga qualquer token anterior — garante no máximo 1 sessão válida, mesmo se for
            // o mesmo aparelho logando de novo (mais simples que comparar identificador antigo
            // x novo, e cobre igualmente o caso de aparelho diferente).
            $usuario->tokens()->delete();

            Dispositivo::updateOrCreate(
                ['usuario_id' => $usuario->id],
                [
                    'identificador' => $credenciais['dispositivo_identificador'],
                    'nome' => $credenciais['dispositivo_nome'] ?? null,
                    'ultimo_acesso_em' => now(),
                ]
            );
        }

        // Log append-only pro histórico de eventos do usuário no admin web (ver
        // UsuarioController::historico) — não confundir com Dispositivo acima, que só guarda o
        // último acesso.
        UsuarioLoginLog::create([
            'usuario_id' => $usuario->id,
            'dispositivo_identificador' => $credenciais['dispositivo_identificador'] ?? null,
            'dispositivo_nome' => $credenciais['dispositivo_nome'] ?? null,
        ]);

        $token = $usuario->createToken('acesso-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => new UsuarioResource($usuario),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user()->loadMissing(['empresa', 'perfil', 'dispositivo']);

        return response()->json([
            'usuario' => new UsuarioResource($usuario),
        ]);
    }
}
