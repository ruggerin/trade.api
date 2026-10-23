<?php

namespace App\Support\Xp;

/**
 * Como o XP de uma visita se divide (docs/88 §5.1) e o nome de cada parte e de cada perda.
 * `PARTES` é o padrão; cada empresa pode mudar os pesos nos parâmetros (`XP_PESO_*`), e a OS
 * congela a composição em vigor quando nasce (`ordens_servico.xp_composicao`).
 */
final class ComposicaoXp
{
    /** Composição padrão: parte => % do xp_base. "Pontualidade e tempo" (20%) vem em duas metades. */
    public const PARTES = [
        'presenca' => 15,
        'completude' => 40,
        'qualidade' => 20,
        'pontualidade' => 10,
        'tempo' => 10,
        'envio' => 5,
    ];

    /** Parte => categoria da perda quando ela não é ganha inteira (§7). */
    public const PERDA_DA_PARTE = [
        'presenca' => 'fora_do_raio',
        'completude' => 'obrigatorias_incompletas',
        'qualidade' => 'qualidade',
        'pontualidade' => 'atraso_inicio',
        'tempo' => 'estouro_duracao',
        'envio' => 'envio_dia_seguinte',
    ];

    public const ROTULOS_PARTE = [
        'presenca' => 'Presença no raio',
        'completude' => 'Obrigatórias',
        'qualidade' => 'Qualidade',
        'pontualidade' => 'Início no horário',
        'tempo' => 'Tempo na loja',
        'envio' => 'Envio no mesmo dia',
    ];

    public const ROTULOS_PERDA = [
        'visita_nao_realizada' => 'Visitas não realizadas',
        'obrigatorias_incompletas' => 'Obrigatórias incompletas',
        'qualidade' => 'Qualidade abaixo de 100%',
        'fora_do_raio' => 'Check-in fora do raio',
        'atraso_inicio' => 'Atraso no início',
        'estouro_duracao' => 'Estouro de duração',
        'envio_dia_seguinte' => 'Envio no dia seguinte',
        'visita_curta' => 'Visita curta demais',
        'reprovado_avaliacao' => 'Reprovado na avaliação',
    ];

    public const INICIATIVA = 'iniciativa';

    public const BONUS_ACAO = 'bonus_acao';

    /** Categoria de ganho do plano (as partes) ou da iniciativa. */
    public static function ehIniciativa(string $categoria): bool
    {
        return in_array($categoria, [self::INICIATIVA, self::BONUS_ACAO], true);
    }

    /**
     * Pontos máximos de cada parte. Arredonda cada uma e joga a sobra na completude, pra soma
     * bater exatamente com o xp_base. Sem composição válida (OS anterior aos pesos
     * configuráveis), a padrão.
     *
     * @param  array<string, int>|null  $composicao  parte => %
     * @return array<string, int>
     */
    public static function maximos(int $xpBase, ?array $composicao = null): array
    {
        $composicao = self::valida($composicao) ? $composicao : self::PARTES;
        $maximos = [];
        foreach (array_keys(self::PARTES) as $parte) {
            $maximos[$parte] = (int) round($xpBase * (int) $composicao[$parte] / 100);
        }
        $maximos['completude'] += $xpBase - array_sum($maximos);

        return $maximos;
    }

    /** As 6 partes, cada uma de 0 a 100, somando 100. */
    public static function valida(?array $composicao): bool
    {
        if ($composicao === null || array_diff_key(self::PARTES, $composicao) !== []) {
            return false;
        }
        $pesos = array_map('intval', array_intersect_key($composicao, self::PARTES));

        return min($pesos) >= 0 && max($pesos) <= 100 && array_sum($pesos) === 100;
    }
}
