<?php

namespace App\Support;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\VisitaRegistro;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fundação de dado do painel "Operação do Dia" (docs/32-PAINEL-OPERACAO-DO-DIA.md, Fase 1) —
 * nada aqui é gravado, tudo calculado na leitura a partir de Visita/OrdemServico/VisitaRegistro
 * de hoje, mesmo racional de StatusFatura/StatusOrdemServico::EXPIRADA (comentário no enum).
 */
class OperacaoDoDia
{
    // Status calculado de um promotor com compromisso hoje — nunca gravado (ver classe).
    public const STATUS_NO_PDV = 'NO_PDV';
    public const STATUS_ENCERRADO = 'ENCERRADO';
    public const STATUS_ATRASADO = 'ATRASADO';
    public const STATUS_DESLOCAMENTO = 'DESLOCAMENTO';

    /**
     * `OrdemServico.horario_previsto` é só um TIME (sem data, ver OrdemServicoResource) — a data
     * de verdade da OS é `prazo_fim`, já usada pra filtrar "hoje" antes de chegar aqui. Ancorar
     * explicitamente nessa data (em vez de deixar `Carbon::parse($horario)` assumir "hoje" pelo
     * relógio do servidor) evita comparação errada perto da meia-noite, quando "daqui a 1h" pode
     * cair no dia seguinte e o parse sem data reconstruiria a hora de volta em "hoje" — ou seja,
     * no passado.
     */
    public static function horarioPrevistoEm(Carbon $dia, string $horarioPrevisto, ?string $fuso = null): Carbon
    {
        // docs/50 §4.2: o horário marcado é da loja — `$dia` é a data local (fuso da empresa) e
        // o resultado volta em UTC, pra comparar com `now()`.
        return Fuso::instanteLocal($dia->toDateString(), $horarioPrevisto, $fuso ?? $dia->tzName);
    }

    /** Horário previsto de uma OS naquele dia, no fuso da loja dela (sem loja, o de `$dia`). */
    public static function horarioPrevistoDaOs(OrdemServico $os, Carbon $dia): Carbon
    {
        return self::horarioPrevistoEm($dia, $os->horario_previsto, $os->pontoVenda ? Fuso::daLoja($os->pontoVenda) : null);
    }

    /** O dia local (00:00 no fuso da empresa) — o "hoje" do dashboard (docs/50 §4.3). */
    public static function hoje(Empresa $empresa): Carbon
    {
        return Fuso::hoje(Fuso::daEmpresa($empresa));
    }

    /**
     * Fase 0 decisão 6: configurável via Parametro `ATRASO_TOLERANCIA_MINUTOS`, default 30 se
     * ausente/inativo/não-numérico — mesmo padrão de fallback de RaioCheckin::metros.
     */
    public static function toleranciaAtrasoMinutos(Empresa $empresa): int
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'ATRASO_TOLERANCIA_MINUTOS')
            ->where('ativo', true)
            ->first();

        if (! $parametro || ! is_numeric($parametro->valor)) {
            return 30;
        }

        return max(0, (int) $parametro->valor);
    }

    /**
     * Fase 0 decisão 5: jornada de trabalho por empresa, dois Parametro (`JORNADA_INICIO`/
     * `JORNADA_FIM`, formato "HH:mm"). Ausente/inativo cai no default 07:00-17:00.
     *
     * @return array{inicio: string, fim: string}
     */
    public static function jornada(Empresa $empresa): array
    {
        $valores = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('chave', ['JORNADA_INICIO', 'JORNADA_FIM'])
            ->where('ativo', true)
            ->pluck('valor', 'chave');

        return [
            'inicio' => $valores['JORNADA_INICIO'] ?? '07:00',
            'fim' => $valores['JORNADA_FIM'] ?? '17:00',
        ];
    }

    /**
     * Status "ao vivo" de cada promotor com Ordem de Serviço prevista pra hoje (`prazo_fim` —
     * mesmo campo que OrdemServicoController/RelatorioController já usam pra "hoje"/"atrasada").
     * NO_PDV tem prioridade (visita aberta agora, não importa o resto); sem visita aberta,
     * ENCERRADO se não sobrou nenhuma OS pendente/em andamento; senão ATRASADO se alguma
     * pendente já passou do `horario_previsto` + tolerância, ou DESLOCAMENTO (sobra).
     *
     * @return Collection<int, array{usuario_id: int, status: string, visita_aberta: ?\App\Models\Visita, ordens: Collection}>
     */
    public static function statusPorPromotor(Empresa $empresa, ?Carbon $dia = null): Collection
    {
        $dia ??= self::hoje($empresa);
        $tolerancia = self::toleranciaAtrasoMinutos($empresa);
        $agora = now();

        return OrdemServico::query()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('usuario_id')
            ->whereBetween('prazo_fim', Fuso::intervaloDoDia($dia->toDateString(), Fuso::daEmpresa($empresa)))
            ->with(['usuario', 'pontoVenda', 'visita'])
            ->get()
            ->groupBy('usuario_id')
            ->map(function (Collection $ordensDoPromotor) use ($tolerancia, $agora, $dia) {
                $visitaAberta = $ordensDoPromotor
                    ->pluck('visita')
                    ->filter(fn ($visita) => $visita && $visita->status === StatusVisita::ABERTA)
                    ->first();

                if ($visitaAberta) {
                    $status = self::STATUS_NO_PDV;
                } else {
                    $pendentes = $ordensDoPromotor->whereIn('status', [
                        StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO,
                    ]);

                    if ($pendentes->isEmpty()) {
                        $status = self::STATUS_ENCERRADO;
                    } else {
                        $temAtraso = $pendentes->contains(
                            fn (OrdemServico $os) => $os->horario_previsto
                                && self::horarioPrevistoDaOs($os, $dia)->addMinutes($tolerancia)->lt($agora),
                        );
                        $status = $temAtraso ? self::STATUS_ATRASADO : self::STATUS_DESLOCAMENTO;
                    }
                }

                return [
                    'usuario' => $ordensDoPromotor->first()->usuario,
                    'status' => $status,
                    'visita_aberta' => $visitaAberta,
                    'ordens' => $ordensDoPromotor,
                ];
            })
            ->values();
    }

    /**
     * Rupturas ainda abertas (registro mais recente daquele produto+PDV com `ruptura=true` e
     * `alerta_resolvido_em` nulo), agrupadas por produto — quantos PDVs distintos e desde quando
     * (o `created_at` mais antigo da sequência não resolvida). Depende do tipo "Ruptura" estar
     * marcado como `eh_alerta` pela empresa (doc 19 §1); se não estiver, volta vazio — nada
     * quebra, só fica sem dado.
     *
     * @return Collection<int, array{produto: \App\Models\ProdutoAuditoria, pdvs: int, desde: Carbon}>
     */
    public static function rupturasAbertasPorSku(Empresa $empresa): Collection
    {
        return VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->where('ruptura', true)
            ->whereNull('alerta_resolvido_em')
            ->whereNotNull('produto_auditoria_id')
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->whereHas('visita', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->with(['produtoAuditoria', 'visita.pontoVenda'])
            ->get()
            ->groupBy('produto_auditoria_id')
            ->map(fn (Collection $registros) => [
                'produto' => $registros->first()->produtoAuditoria,
                'pdvs' => $registros->pluck('visita.ponto_venda_id')->unique()->count(),
                'desde' => $registros->min('created_at'),
            ])
            ->sortByDesc('pdvs')
            ->values();
    }

    /**
     * "N expedidos, M preenchidos" pra Ordens de Serviço com `prazo_fim` hoje — mesma query de
     * App\Support\ProgressoDirecionamento::calcular, só trocando o filtro de `direcionamento_id`
     * por "hoje" (nenhuma modelagem nova, ver docs/32-PAINEL-OPERACAO-DO-DIA.md Fase 0 item 1).
     *
     * @return array{expedidos: int, preenchidos: int}
     */
    public static function formulariosEmitidosHoje(Empresa $empresa, ?Carbon $dia = null): array
    {
        $dia ??= self::hoje($empresa);

        $linha = DB::table('ordem_servico_formularios')
            ->join('ordens_servico', 'ordens_servico.id', '=', 'ordem_servico_formularios.ordem_servico_id')
            ->where('ordens_servico.empresa_id', $empresa->id)
            ->whereBetween('ordens_servico.prazo_fim', Fuso::intervaloDoDia($dia->toDateString(), Fuso::daEmpresa($empresa)))
            ->selectRaw('count(*) as expedidos, count(ordem_servico_formularios.respondido_em) as preenchidos')
            ->first();

        return [
            'expedidos' => (int) ($linha->expedidos ?? 0),
            'preenchidos' => (int) ($linha->preenchidos ?? 0),
        ];
    }
}
