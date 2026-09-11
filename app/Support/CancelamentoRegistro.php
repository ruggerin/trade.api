<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Se o promotor pode cancelar (soft, nunca apaga) um registro que ele mesmo fez — ver
 * VisitaRegistroController::cancelar. Mesmo padrão de leitura de `Parametro` já usado por
 * AutonomiaAgenda. Ausente/inativo = default `false` (conservador de propósito: é uma
 * capacidade nova, a empresa liga quando quiser). Só vale pra PROMOTOR — ADMIN/GESTOR sempre
 * podem cancelar qualquer registro da própria empresa, mesmo raciocínio de EnsurePermissao (o
 * dono do negócio nunca fica travado por um parâmetro pensado pro autosserviço do promotor).
 */
class CancelamentoRegistro
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function permitidoParaPromotor(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'REGISTRO_CANCELAMENTO_PERMITIDO')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}
