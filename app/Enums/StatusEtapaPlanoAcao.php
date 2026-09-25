<?php

namespace App\Enums;

/**
 * Status de uma etapa de PlanoAcao — ver docs/37-PLANOS-DE-ACAO.md §4.5. "Atrasada" não é um
 * estado gravado: é calculado na exibição (prazo vencido e ainda não FEITA/CANCELADA), mesmo
 * padrão de StatusOrdemServico ("expirada") e StatusFatura ("atrasada"). Etapa "pulada"
 * reaproveita CANCELADA com motivo, em vez de virar estado à parte.
 */
enum StatusEtapaPlanoAcao: string
{
    case PENDENTE = 'PENDENTE';
    case EM_ANDAMENTO = 'EM_ANDAMENTO';
    case FEITA = 'FEITA';
    case CANCELADA = 'CANCELADA';
    // Algo quebrou no caminho (o vendedor nunca foi, a OS vinculada foi cancelada) — sinaliza em
    // vez de voltar silenciosamente pra PENDENTE. Sai daqui só por decisão de quem tem
    // planos_acao.movimentar_etapa.
    case BLOQUEADA = 'BLOQUEADA';

    public function finalizada(): bool
    {
        return $this === self::FEITA || $this === self::CANCELADA;
    }

    /**
     * Transições permitidas a partir deste status. FEITA/CANCELADA são terminais — corrigir um
     * engano vira etapa nova, nunca reabrir (preserva a trilha do histórico, §4.9).
     *
     * @return list<self>
     */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::PENDENTE => [self::EM_ANDAMENTO, self::FEITA, self::CANCELADA, self::BLOQUEADA],
            self::EM_ANDAMENTO => [self::PENDENTE, self::FEITA, self::CANCELADA, self::BLOQUEADA],
            self::BLOQUEADA => [self::PENDENTE, self::EM_ANDAMENTO, self::FEITA, self::CANCELADA],
            self::FEITA, self::CANCELADA => [],
        };
    }
}
