<?php

namespace App\Support\Xp;

/**
 * Como o XP de uma visita se divide (docs/88 §5.1) e o nome de cada parte e de cada perda. A
 * composição é fixa na v1, igual pra todas as empresas (dá pra comparar uma com a outra); quem
 * dá flexibilidade é a classe da loja.
 */
final class ComposicaoXp
{
    /** Parte => % do xp_base. "Pontualidade e tempo" (20%) vem em duas metades. */
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
     * bater exatamente com o xp_base.
     *
     * @return array<string, int>
     */
    public static function maximos(int $xpBase): array
    {
        $maximos = [];
        foreach (self::PARTES as $parte => $percentual) {
            $maximos[$parte] = (int) round($xpBase * $percentual / 100);
        }
        $maximos['completude'] += $xpBase - array_sum($maximos);

        return $maximos;
    }
}
