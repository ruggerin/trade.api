<?php

namespace App\Support;

use App\Enums\CheckoutTipo;
use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\OrdemServico;
use App\Models\Visita;
use App\Support\Xp\ReguaXp;
use Illuminate\Support\Carbon;

/**
 * Fecha uma visita com hora e posição do checkout — o mesmo caminho pro checkout do app
 * (PROMOTOR / SAIDA_DETECTADA) e pro fechamento automático do servidor (AUTOMATICO, docs/92 §7).
 * O checkout forçado pelo gestor (ADMIN, sem GPS) tem fluxo próprio no VisitaController.
 */
final class FinalizacaoVisita
{
    public static function distanciaDaLoja(Visita $visita, float $latitude, float $longitude): float
    {
        $visita->loadMissing('pontoVenda');

        return Haversine::metros($latitude, $longitude, (float) $visita->pontoVenda->latitude, (float) $visita->pontoVenda->longitude);
    }

    public static function finalizar(Visita $visita, Carbon $fim, float $latitude, float $longitude, CheckoutTipo $tipo, array $extras = []): void
    {
        $empresa = $visita->empresa()->withoutGlobalScopes()->first();
        $distancia = self::distanciaDaLoja($visita, $latitude, $longitude);

        // Raio do checkout (docs/92 peça A): null = a empresa não exige — nada a confirmar.
        $raio = CheckoutParametros::raioMetros($empresa);
        $noRaio = $raio === null ? null : $distancia <= $raio;

        $visita->update([
            'fim_data' => $fim,
            'checkout_recebido_em' => now(),
            'fim_latitude' => $latitude,
            'fim_longitude' => $longitude,
            'fim_distancia_metros' => $distancia,
            'status' => StatusVisita::FINALIZADA,
            'checkout_tipo' => $tipo,
            'checkout_no_raio' => $noRaio,
            // Entrada e saída dentro do raio: o tempo em loja está confirmado pelo GPS.
            'tempo_confirmado_gps' => $raio === null ? null : $noRaio && (float) $visita->inicio_distancia_metros <= RaioCheckin::metros($empresa),
            ...$extras,
        ]);

        if ($visita->ordem_servico_id) {
            OrdemServico::withoutGlobalScopes()
                ->where('id', $visita->ordem_servico_id)
                ->update(['status' => StatusOrdemServico::CONCLUIDA]);
        }

        // Régua de XP (docs/88): calcula a visita assim que o check-out chega.
        ReguaXp::recalcularVisita($visita);
        // Régua do mix (docs/90): copia o mix da loja no momento em que o check-out chega.
        MixDaVisita::gravar($visita);
    }
}
