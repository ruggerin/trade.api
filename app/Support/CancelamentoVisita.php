<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Se o promotor pode cancelar (anular) a própria visita em andamento — ver
 * VisitaController::cancelarPropria. Mesmo padrão de leitura de `Parametro` já usado por
 * CancelamentoRegistro. Ausente/inativo = default `false` (conservador de propósito: é uma
 * capacidade nova, a empresa liga quando quiser). Só vale pra PROMOTOR cancelando a própria
 * visita — ADMIN/GESTOR já têm a ferramenta de intervenção administrativa
 * (visitas.intervir, ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md), que não depende deste
 * parâmetro.
 */
class CancelamentoVisita
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function permitidoParaPromotor(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'VISITA_CANCELAMENTO_PERMITIDO')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}
