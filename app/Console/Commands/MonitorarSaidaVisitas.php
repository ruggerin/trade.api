<?php

namespace App\Console\Commands;

use App\Enums\CheckoutTipo;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Visita;
use App\Support\AfastamentoVisita;
use App\Support\CheckoutParametros;
use App\Support\FinalizacaoVisita;
use App\Support\Fuso;
use App\Support\Push;
use App\Support\Rastreamento;
use Illuminate\Console\Command;

/**
 * Visitas abertas com saída detectada (docs/92 §6 e §7), a cada 5 min, só nas empresas que ligaram:
 *
 * - Peça C (`CHECKOUT_AVISO_SAIDA_MINUTOS`): N min depois da saída, UM push pro promotor
 *   ("parece que você saiu sem finalizar") — `saida_avisada_em` impede repetir.
 * - Peça D (`CHECKOUT_AUTOMATICO_MINUTOS`): N min depois da saída, fecha a visita no horário e na
 *   posição da saída (`checkout_tipo = AUTOMATICO`).
 *
 * "Ainda estou na loja" depois da saída (`saida_contestada_em`) silencia os dois.
 */
class MonitorarSaidaVisitas extends Command
{
    protected $signature = 'visitas:monitorar-saida';

    protected $description = 'Avisa e/ou finaliza sozinho as visitas abertas em que o promotor já saiu da loja (docs/92)';

    /** Visita aberta há mais que isso não tem mais histórico útil (limite da hora do campo). */
    private const JANELA_HORAS = 48;

    public function handle(): int
    {
        $avisadas = 0;
        $finalizadas = 0;

        Empresa::query()->each(function (Empresa $empresa) use (&$avisadas, &$finalizadas) {
            $aviso = CheckoutParametros::avisoSaidaMinutos($empresa);
            $automatico = CheckoutParametros::automaticoMinutos($empresa);
            if (($aviso === 0 && $automatico === 0) || ! Rastreamento::habilitado($empresa)) {
                return;
            }
            $raio = CheckoutParametros::raioMetros($empresa);
            $fuso = Fuso::daEmpresa($empresa);

            Visita::query()
                ->withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('status', StatusVisita::ABERTA)
                ->where('inicio_data', '>=', now()->subHours(self::JANELA_HORAS))
                ->with(['pontoVenda' => fn ($q) => $q->withoutGlobalScopes()])
                ->chunkById(100, function ($visitas) use ($aviso, $automatico, $raio, $fuso, &$avisadas, &$finalizadas) {
                    foreach ($visitas as $visita) {
                        $saida = AfastamentoVisita::saidaDetectada($visita, $raio);
                        // Contestou depois de sair: o promotor diz que ainda está lá — não insiste.
                        if ($saida === null || ($visita->saida_contestada_em && $visita->saida_contestada_em->gte($saida['saiu_em']))) {
                            continue;
                        }

                        if ($automatico > 0 && $saida['minutos_fora'] >= $automatico) {
                            FinalizacaoVisita::finalizar($visita, $saida['saiu_em'], $saida['latitude'], $saida['longitude'], CheckoutTipo::AUTOMATICO);
                            // Texto genérico, sem nome de loja (LGPD, docs/77 §3.3).
                            Push::paraUsuarios(
                                [$visita->usuario_id],
                                'Visita finalizada automaticamente',
                                sprintf('Sua visita foi finalizada às %s, quando você saiu da loja.', $saida['saiu_em']->copy()->setTimezone($fuso)->format('H:i')),
                                ['tipo' => 'visita_finalizada_automaticamente', 'visita_id' => $visita->uuid],
                                Push::CANAL_AGENDA,
                            );
                            $finalizadas++;

                            continue;
                        }

                        if ($aviso > 0 && $visita->saida_avisada_em === null && $saida['minutos_fora'] >= $aviso) {
                            Push::paraUsuarios(
                                [$visita->usuario_id],
                                'Visita ainda aberta',
                                sprintf('Parece que você saiu da loja há %d min sem finalizar.', $saida['minutos_fora']),
                                ['tipo' => 'saida_detectada', 'visita_id' => $visita->uuid],
                                Push::CANAL_AGENDA,
                                // Botões "Finalizar" e "Ainda estou na loja" (app 1.2.3+; o antigo ignora).
                                'saida_detectada',
                            );
                            $visita->forceFill(['saida_avisada_em' => now()])->saveQuietly();
                            $avisadas++;
                        }
                    }
                });
        });

        $this->info("{$avisadas} aviso(s) enviado(s), {$finalizadas} visita(s) finalizada(s) automaticamente.");

        return self::SUCCESS;
    }
}
