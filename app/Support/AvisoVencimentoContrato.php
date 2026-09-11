<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Quantos dias de antecedência um Contrato (comodato/ponto extra) prestes a vencer deve gerar
 * uma OrdemServico de aviso — mesmo padrão de App\Support\RaioCheckin (parametro `Parametro` da
 * empresa, com fallback pra variável de ambiente). Diferente do raio de check-in, não existe um
 * terceiro estado "desativado = sem aviso nenhum" aqui — desativar o parametro só volta pro
 * default, mesmo comportamento do resto do catálogo de parâmetros (só CHECKIN_RAIO_METROS é
 * especial nesse sentido, ver docs/02-API-BACKEND.md regra de negócio 1).
 */
class AvisoVencimentoContrato
{
    public static function dias(Empresa $empresa): int
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'CONTRATO_AVISO_DIAS')
            ->where('ativo', true)
            ->first();

        if ($parametro && is_numeric($parametro->valor)) {
            return (int) $parametro->valor;
        }

        return (int) env('CONTRATO_AVISO_DIAS', 30);
    }
}
