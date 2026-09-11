<?php

namespace App\Http\Middleware;

use App\Enums\Permissao;
use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorização por permissão granular (RBAC por empresa) — ver App\Models\Perfil e
 * docs/02-API-BACKEND.md. Uso: middleware('permissao:pontos_venda.gerenciar').
 *
 * ADMIN sempre libera (acesso total, nunca restringível por perfil — garante que a empresa
 * nunca fica trancada pra fora configurando um perfil errado). GESTOR depende do perfil
 * atribuído. PROMOTOR nunca passa por essas rotas.
 *
 * SUPERADMIN é um caso especial e restrito: só passa em `usuarios.gerenciar` e
 * `contratos.gerenciar`, e só em GET (buscar de qualquer empresa, não pertence a nenhum tenant
 * então BelongsToEmpresa não filtra a query pra ele), POST (criar numa empresa à escolha, ver
 * StoreUsuarioRequest::empresa_uuid / StoreContratoRequest::empresa_uuid) ou PUT (editar um
 * registro já existente de qualquer empresa) — útil pro suporte resolver chamado ou cadastrar
 * contrato em nome do cliente sem precisar de acesso na empresa dele. Nunca em DELETE —
 * "desativar" direto continua exclusivo do ADMIN/GESTOR daquela empresa (dá pra desativar por
 * PUT com `ativo: false`, só não pelo botão dedicado). Não é um bypass geral: outras chaves de
 * permissão (catalogo.gerenciar, campanhas.gerenciar, etc.) continuam bloqueadas pra SUPERADMIN.
 */
class EnsurePermissao
{
    private const PERMISSOES_LIBERADAS_PARA_SUPERADMIN = [
        Permissao::USUARIOS_GERENCIAR,
        Permissao::CONTRATOS_GERENCIAR,
    ];

    public function handle(Request $request, Closure $next, string $permissao): Response
    {
        $usuario = $request->user();
        $permissaoEnum = Permissao::from($permissao);

        $liberado = match ($usuario?->user_type) {
            UserType::ADMIN => true,
            UserType::GESTOR => $usuario->perfil?->tem($permissaoEnum) ?? false,
            UserType::SUPERADMIN => in_array($permissaoEnum, self::PERMISSOES_LIBERADAS_PARA_SUPERADMIN, true)
                && in_array($request->method(), ['GET', 'POST', 'PUT'], true),
            default => false,
        };

        if (! $liberado) {
            abort(403, 'Você não tem permissão para executar esta ação.');
        }

        return $next($request);
    }
}
