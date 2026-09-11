<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Raio de check-in (metros) — três estados possíveis pro `Parametro` `CHECKIN_RAIO_METROS` da
 * empresa, ver docs/02-API-BACKEND.md, regra de negócio 1:
 * - Não cadastrado → usa a variável de ambiente CHECKIN_RAIO_METROS (default 200).
 * - Cadastrado e ativo, com valor numérico → usa esse valor.
 * - Cadastrado e **inativo** → retorna `INF` (sem limite de distância nenhum).
 *
 * O terceiro caso é diferente do resto do catálogo de parâmetros (onde "desativar" só volta
 * pro comportamento padrão do sistema, nunca desliga uma validação) — pra este parâmetro
 * específico, "desativar" precisa significar "sem restrição de raio", decisão confirmada com o
 * usuário depois de um teste real onde o padrão (200m) acabou sendo mais restritivo que o valor
 * customizado que ele tinha desativado por engano.
 */
class RaioCheckin
{
    public static function metros(Empresa $empresa): float
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'CHECKIN_RAIO_METROS')
            ->first();

        if (! $parametro) {
            return (float) env('CHECKIN_RAIO_METROS', 200);
        }

        if (! $parametro->ativo) {
            return INF;
        }

        return is_numeric($parametro->valor) ? (float) $parametro->valor : (float) env('CHECKIN_RAIO_METROS', 200);
    }
}
