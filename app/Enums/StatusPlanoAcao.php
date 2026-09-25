<?php

namespace App\Enums;

/**
 * Status de um PlanoAcao — ver docs/37-PLANOS-DE-ACAO.md §4.5. Sem estado `BLOQUEADO` próprio de
 * propósito: se uma etapa trava, isso já aparece destacado na lista de etapas, não precisa
 * duplicar o sinal no nível de cima.
 */
enum StatusPlanoAcao: string
{
    case ABERTO = 'ABERTO';
    // Automático assim que a primeira etapa é movimentada — ninguém "inicia" um plano na mão.
    case EM_ANDAMENTO = 'EM_ANDAMENTO';
    case CONCLUIDO = 'CONCLUIDO';
    case CANCELADO = 'CANCELADO';

    /** @return list<self> */
    public static function ativos(): array
    {
        return [self::ABERTO, self::EM_ANDAMENTO];
    }

    public function ativo(): bool
    {
        return in_array($this, self::ativos(), true);
    }
}
