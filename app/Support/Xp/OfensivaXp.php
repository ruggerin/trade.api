<?php

namespace App\Support\Xp;

use App\Models\Empresa;
use App\Models\XpResumoDiario;
use Illuminate\Support\Carbon;

/**
 * Ofensiva (docs/88 §12.3): dias seguidos com a agenda do dia cumprida — todas as OS feitas.
 * Dia sem OS (folga) não quebra nem conta. Não feita com responsável LOJA/EMPRESA, ou justificada
 * pelo gestor, protege (o "protetor" do Duolingo). Hoje só entra se já estiver cumprido; ainda em
 * andamento, não quebra.
 */
final class OfensivaXp
{
    private const JANELA_DIAS = 400;

    public static function dias(Empresa $empresa, int $usuarioId): int
    {
        $fuso = DiaXp::fusoDa($empresa);
        $hoje = now($fuso)->toDateString();
        $resumos = XpResumoDiario::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('dia', '>=', Carbon::parse($hoje)->subDays(self::JANELA_DIAS)->toDateString())
            ->where('visitas_previstas', '>', 0)
            ->orderByDesc('dia')
            ->get();

        $seguidos = 0;
        foreach ($resumos as $resumo) {
            $cumprido = $resumo->visitas_feitas + $resumo->visitas_protegidas >= $resumo->visitas_previstas;
            if ($resumo->dia->toDateString() === $hoje) {
                if ($cumprido) {
                    $seguidos++;
                }

                continue;
            }
            if (! $cumprido) {
                break;
            }
            $seguidos++;
        }

        return $seguidos;
    }
}
