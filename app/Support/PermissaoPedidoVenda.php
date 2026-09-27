<?php

namespace App\Support;

use App\Enums\Permissao;
use App\Enums\UserType;
use App\Models\Usuario;

/**
 * Checagem de pedidos_venda.* — diferente de Usuario::temPermissao()/EnsurePermissao (só
 * ADMIN/GESTOR), aqui o PROMOTOR também conta: é o "modo Vendedor" de docs/38-PEDIDO-VENDEDOR.md
 * §4. Mesmo precedente de VisibilidadePontosVenda, que olha `$usuario->perfil?->tem()` direto.
 */
class PermissaoPedidoVenda
{
    public static function tem(Usuario $usuario, Permissao $permissao): bool
    {
        // Módulo pago por empresa (docs/38-PEDIDO-VENDEDOR.md §12) — sem ele, nenhuma das três
        // permissões vale, nem pra ADMIN, não importa o que o Perfil diga.
        if (! self::moduloHabilitado($usuario)) {
            return false;
        }

        return match ($usuario->user_type) {
            UserType::ADMIN => true,
            UserType::GESTOR, UserType::PROMOTOR => $usuario->perfil?->tem($permissao) ?? false,
            default => false,
        };
    }

    public static function moduloHabilitado(Usuario $usuario): bool
    {
        return (bool) $usuario->empresa?->pedidos_venda_habilitado;
    }

    /** Qualquer uma das três — quem cria precisa ver os próprios, quem aprova precisa ver a fila. */
    public static function acessa(Usuario $usuario): bool
    {
        return self::tem($usuario, Permissao::PEDIDOS_VENDA_VISUALIZAR)
            || self::tem($usuario, Permissao::PEDIDOS_VENDA_CRIAR)
            || self::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR);
    }

    /** Só vê os próprios, salvo se também aprova (§5) — a fila de autorização é da empresa toda. */
    public static function vePedidosDeTodos(Usuario $usuario): bool
    {
        return self::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR);
    }
}
