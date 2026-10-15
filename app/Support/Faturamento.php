<?php

namespace App\Support;

use App\Enums\Permissao;
use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;

/**
 * Quem pode ver valor, lucro e faturamento (docs/61 §5.2): a empresa liga o parâmetro
 * `FATURAMENTO_HABILITADO` (padrão DESLIGADO) E o usuário é ADMIN, ou GESTOR com a permissão
 * `faturamento.visualizar`. PROMOTOR nunca — nem com o parâmetro ligado. O que o integrador manda
 * continua sendo gravado com o parâmetro desligado; só não é exposto.
 */
final class Faturamento
{
    private const VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function habilitado(?Empresa $empresa): bool
    {
        if ($empresa === null) {
            return false;
        }

        $parametro = Parametro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'FATURAMENTO_HABILITADO')
            ->where('ativo', true)
            ->first();

        return $parametro !== null && in_array(strtolower((string) $parametro->valor), self::VERDADEIROS, true);
    }

    public static function podeVer(?Usuario $usuario): bool
    {
        if ($usuario === null || ! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            return false;
        }

        return self::habilitado($usuario->empresa) && $usuario->temPermissao(Permissao::FATURAMENTO_VISUALIZAR);
    }
}
