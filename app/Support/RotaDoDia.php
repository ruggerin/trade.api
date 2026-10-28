<?php

namespace App\Support;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monta a Rota do dia de um promotor — docs/48-ROTA-DO-DIA.md §4.3: pontos do histórico, visitas
 * (os pontos numerados), paradas fora de loja, buracos sem sinal, a linha seguindo as ruas (§4.4,
 * com cache em `rotas_dia`) e o resumo do dia.
 *
 * docs/93: recorte no expediente (RASTREAMENTO_ROTA_RECORTE), deslocamentos com horário e bairros,
 * e trechos fora da área do roteiro (longe de todas as lojas do dia).
 */
final class RotaDoDia
{
    /** Pontos a até isso do primeiro ponto da parada contam como "o mesmo lugar". */
    private const RAIO_PARADA_METROS = 100;

    /** Loja cadastrada a até isso da parada = não é "fora de loja". */
    private const RAIO_LOJA_METROS = 300;

    /** Folga nas bordas da visita (check-in um pouco depois de chegar, checkout antes de sair). */
    private const FOLGA_VISITA_MINUTOS = 5;

    /** Deslocamento mais curto que isso é ruído do GPS (ou a volta no estacionamento) — não entra. */
    private const DESLOCAMENTO_MINIMO_METROS = 500;

    /** Pontos amostrados por trecho pra descobrir os bairros (o primeiro e o último sempre entram). */
    private const AMOSTRAS_BAIRRO = 6;

    /**
     * `$dia` no fuso da empresa (docs/50 §4.3): o dia vai da meia-noite à meia-noite desse fuso.
     * O recorte vira UTC — é como o instante está no banco, independente do fuso do servidor.
     */
    public static function montar(Usuario $promotor, Carbon $dia): array
    {
        $empresa = $promotor->empresa;
        [$inicioDia, $fimDia] = Fuso::intervaloDoDia($dia->toDateString(), $dia->tzName);
        $recorte = Rastreamento::rotaRecorte($empresa);
        $expediente = self::expediente($empresa, $dia);
        $recortado = $recorte !== 'DIA_INTEIRO';
        $intervalo = $expediente['intervalo'];

        $foraDoExpediente = fn (Carbon $em) => $em->lt($expediente['inicio']) || $em->gt($expediente['fim']);
        $noIntervalo = fn (Carbon $em) => $intervalo !== null && $em->gte($intervalo['inicio']) && $em->lte($intervalo['fim']);
        // O que a tela esconde (recolhido, ou nem vem no SO_EXPEDIENTE): fora do expediente quando há
        // recorte, e o intervalo quando a empresa o desconsidera (docs/95) — em qualquer recorte.
        $escondeAlgo = $recortado || $intervalo !== null;
        $oculto = fn (Carbon $em) => ($recortado && $foraDoExpediente($em)) || $noIntervalo($em);

        // SO_EXPEDIENTE: fora do horário (e o intervalo) nem sai do banco (docs/93 §3, docs/95).
        [$deHistorico, $ateHistorico] = $recorte === 'SO_EXPEDIENTE' ? [$expediente['inicio'], $expediente['fim']] : [$inicioDia, $fimDia];

        /** @var list<array{lat: float, lng: float, em: Carbon}> $pontos */
        $pontos = DB::table('localizacoes_historico')
            ->where('usuario_id', $promotor->id)
            ->whereBetween('capturado_em', [$deHistorico, $ateHistorico])
            ->when($recorte === 'SO_EXPEDIENTE' && $intervalo !== null, fn ($q) => $q->whereNotBetween('capturado_em', [$intervalo['inicio'], $intervalo['fim']]))
            ->orderBy('capturado_em')
            ->get(['latitude', 'longitude', 'capturado_em'])
            ->map(fn ($p) => ['lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'em' => Carbon::parse($p->capturado_em)])
            ->all();

        $visitas = Visita::query()
            ->where('usuario_id', $promotor->id)
            ->where('status', '!=', StatusVisita::CANCELADA)
            ->whereBetween('inicio_data', [$inicioDia, $fimDia])
            ->with('pontoVenda')
            ->orderBy('inicio_data')
            ->get();

        $tolerancia = Rastreamento::toleranciaSemSinalMinutos($empresa);

        // Trechos contínuos, separados onde ficou tempo demais sem posição chegar — e também na
        // entrada/saída do que a tela esconde (expediente, intervalo), pra ela poder esconder.
        // O lado do trecho é o do ÚLTIMO ponto: o primeiro pode ser o ponto da virada repetido.
        $segmentos = [];
        $segmentosFora = [];
        $semSinal = [];
        $atual = [];
        foreach ($pontos as $i => $p) {
            $anterior = $pontos[$i - 1] ?? null;
            if ($anterior && $anterior['em']->diffInMinutes($p['em'], true) > $tolerancia) {
                $semSinal[] = [
                    'inicio' => $anterior['em'],
                    'fim' => $p['em'],
                    'minutos' => (int) round($anterior['em']->diffInMinutes($p['em'], true)),
                    'de' => ['latitude' => $anterior['lat'], 'longitude' => $anterior['lng']],
                    'ate' => ['latitude' => $p['lat'], 'longitude' => $p['lng']],
                ];
                $segmentos[] = $atual;
                $segmentosFora[] = $oculto($atual[count($atual) - 1]['em']);
                $atual = [];
            } elseif ($anterior && $escondeAlgo && $oculto($anterior['em']) !== $oculto($p['em'])) {
                $segmentos[] = $atual;
                $segmentosFora[] = $oculto($atual[count($atual) - 1]['em']);
                $atual = [$anterior]; // repete o ponto da virada: a linha não fica com buraco
            }
            $atual[] = $p;
        }
        if ($atual) {
            $segmentos[] = $atual;
            $segmentosFora[] = $oculto($atual[count($atual) - 1]['em']);
        }

        $paradas = self::paradas($pontos, $visitas, $tolerancia, Rastreamento::paradaMinutos($empresa), $promotor->empresa_id);
        ['linhas' => $linhas, 'aproximada' => $aproximada] = self::linhas($promotor, $dia, $segmentos);

        $distancia = 0.0;
        foreach ($segmentos as $s => $segmento) {
            if ($segmentosFora[$s]) {
                continue;
            }
            $distancia += self::metros($segmento);
        }

        $visitasFormatadas = $visitas->values()->map(function (Visita $v, int $i) use ($pontos) {
            $fim = $v->fim_data;
            // Saiu da loja no meio da visita (docs/49) — com os pontos do dia já carregados.
            $afastamento = AfastamentoVisita::calcular($v, $pontos);

            return [
                'id' => $v->uuid,
                'ordem' => $i + 1,
                'status' => $v->status,
                'ponto_venda' => $v->pontoVenda ? [
                    'id' => $v->pontoVenda->uuid,
                    'fantasia' => $v->pontoVenda->fantasia,
                ] : null,
                // Pino na loja; sem coordenada cadastrada, no ponto do check-in.
                'latitude' => (float) ($v->pontoVenda?->latitude ?? $v->inicio_latitude),
                'longitude' => (float) ($v->pontoVenda?->longitude ?? $v->inicio_longitude),
                'inicio' => $v->inicio_data,
                'fim' => $fim,
                'minutos' => $fim ? (int) round($v->inicio_data->diffInMinutes($fim, true)) : null,
                // SAIDA_DETECTADA/AUTOMATICO (docs/92): a tela troca o alerta por uma nota neutra.
                'checkout_tipo' => $v->checkout_tipo?->value,
                'afastamentos' => $afastamento['afastamentos'] ?? [],
                'minutos_fora' => $afastamento['minutos_fora'] ?? 0,
                'sem_sinal_minutos' => $afastamento['sem_sinal_minutos'] ?? 0,
            ];
        });

        $raioDesvio = Rastreamento::desvioRaioMetros($empresa);
        $lojasRoteiro = $raioDesvio > 0 ? self::lojasDoRoteiro($promotor, $dia, $visitas) : [];
        $deslocamentos = self::deslocamentos($pontos, $visitas, $paradas, $tolerancia, $escondeAlgo ? $oculto : null);
        // Desvio só no expediente e nunca no intervalo, em qualquer recorte.
        $desvios = self::desvios($pontos, $visitas, $lojasRoteiro, $raioDesvio, Rastreamento::desvioMinutos($empresa), $tolerancia, fn (Carbon $em) => $foraDoExpediente($em) || $noIntervalo($em));
        self::preencherBairros($deslocamentos, $desvios, $paradas);

        // O resumo conta só o que fica à mostra (expediente com recorte, sem o intervalo); o que ficou
        // escondido vira um resumo à parte, por bloco.
        $visiveis = self::janelasVisiveis($recortado ? [$expediente['inicio'], $expediente['fim']] : [$inicioDia, $fimDia], $intervalo);
        $resumoEscondido = array_filter([
            'antes' => $recorte === 'EXPEDIENTE' ? self::resumoFora($segmentos, $segmentosFora, $paradas, $semSinal, $inicioDia, $expediente['inicio']) : null,
            'intervalo' => $intervalo !== null && $recorte !== 'SO_EXPEDIENTE' ? self::resumoFora($segmentos, $segmentosFora, $paradas, $semSinal, $intervalo['inicio'], $intervalo['fim']) : null,
            'depois' => $recorte === 'EXPEDIENTE' ? self::resumoFora($segmentos, $segmentosFora, $paradas, $semSinal, $expediente['fim'], $fimDia) : null,
        ]);

        return [
            'data' => $dia->toDateString(),
            'parametros' => [
                'parada_minutos' => Rastreamento::paradaMinutos($empresa),
                'afastamento_metros' => Rastreamento::afastamentoMetros($empresa),
                'afastamento_minutos' => Rastreamento::afastamentoMinutos($empresa),
                'tolerancia_sem_sinal_minutos' => $tolerancia,
                'desvio_raio_metros' => $raioDesvio,
                'desvio_minutos' => Rastreamento::desvioMinutos($empresa),
            ],
            'expediente' => [
                'recorte' => $recorte,
                'inicio' => $expediente['inicio']->toIso8601String(),
                'fim' => $expediente['fim']->toIso8601String(),
                // Intervalo desconsiderado (docs/95); null = a empresa não desconsidera.
                'intervalo' => $intervalo ? [
                    'inicio' => $intervalo['inicio']->toIso8601String(),
                    'fim' => $intervalo['fim']->toIso8601String(),
                ] : null,
            ],
            'pontos' => array_map(fn ($p) => ['latitude' => $p['lat'], 'longitude' => $p['lng'], 'em' => $p['em']->toIso8601String()], $pontos),
            'linhas' => $linhas,
            // Paralelo a `linhas`: true = trecho escondido (fora do expediente com recorte, ou intervalo).
            'linhas_fora_expediente' => $segmentos ? $segmentosFora : [],
            'aproximada' => $aproximada,
            'sem_sinal' => $semSinal,
            'visitas' => $visitasFormatadas,
            'paradas' => $paradas,
            'deslocamentos' => $deslocamentos,
            'desvios' => $desvios,
            'area_roteiro' => [
                'raio_metros' => $raioDesvio,
                'lojas' => $lojasRoteiro,
            ],
            // O que estava planejado pro dia e não virou visita (não visitada, a vencer, em andamento,
            // cancelada com motivo) — a tela mostra com o checkbox "Planejadas não feitas". Mesma
            // classificação do relatório de cumprimento (App\Support\LojasDoPlanejamento).
            'planejadas_nao_feitas' => LojasDoPlanejamento::itens($inicioDia, $fimDia, $dia->tzName, $promotor->id, null)
                ->whereNotIn('situacao', [LojasDoPlanejamento::VISITADA, LojasDoPlanejamento::ESPONTANEA])
                ->values(),
            'resumo' => [
                'visitas' => $visitasFormatadas->count(),
                // Tempo em loja honesto (docs/49 §4): desconta o tempo fora durante as visitas.
                'tempo_em_loja_minutos' => max(0, (int) $visitasFormatadas->sum('minutos') - (int) $visitasFormatadas->sum('minutos_fora')),
                'fora_da_loja_minutos' => (int) $visitasFormatadas->sum('minutos_fora'),
                'distancia_km' => round($distancia / 1000, 1),
                'parado_fora_minutos' => self::minutosNasJanelas($paradas, $visiveis),
                'sem_sinal_minutos' => self::minutosNasJanelas($semSinal, $visiveis),
                'fora_da_area_minutos' => (int) collect($desvios)->sum('minutos'),
                'primeira_posicao_em' => $pontos ? $pontos[0]['em']->toIso8601String() : null,
                'ultima_posicao_em' => $pontos ? $pontos[count($pontos) - 1]['em']->toIso8601String() : null,
            ],
            // Blocos escondidos: `antes`/`depois` (recorte EXPEDIENTE) e `intervalo` (docs/95, fora do
            // SO_EXPEDIENTE). null = nada escondido que o gestor possa abrir.
            'fora_do_expediente' => $resumoEscondido ?: null,
        ];
    }

    /**
     * Expediente do dia em UTC. Hoje é o horário fixo da empresa (JORNADA_INICIO/FIM); quando o
     * controle de jornada (doc 81) existir, entra aqui a marcação real do promotor.
     *
     * `intervalo`: o almoço que a empresa desconsidera (docs/95), ou null.
     *
     * @return array{inicio: Carbon, fim: Carbon, intervalo: array{inicio: Carbon, fim: Carbon}|null}
     */
    private static function expediente(Empresa $empresa, Carbon $dia): array
    {
        $jornada = OperacaoDoDia::jornada($empresa);
        $data = $dia->toDateString();
        $hora = fn (string $hhmm) => Carbon::parse("{$data} {$hhmm}", $dia->tzName)->utc();

        return [
            'inicio' => $hora($jornada['inicio']),
            'fim' => $hora($jornada['fim']),
            'intervalo' => $jornada['intervalo'] ? ['inicio' => $hora($jornada['intervalo']['inicio']), 'fim' => $hora($jornada['intervalo']['fim'])] : null,
        ];
    }

    /**
     * A janela à mostra, sem o intervalo no meio.
     *
     * @param  array{0: Carbon, 1: Carbon}  $janela
     * @param  array{inicio: Carbon, fim: Carbon}|null  $intervalo
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private static function janelasVisiveis(array $janela, ?array $intervalo): array
    {
        if ($intervalo === null) {
            return [$janela];
        }

        return array_values(array_filter(
            [[$janela[0], $intervalo['inicio']->min($janela[1])], [$intervalo['fim']->max($janela[0]), $janela[1]]],
            fn ($j) => $j[1]->gt($j[0]),
        ));
    }

    /** @param  list<array{0: Carbon, 1: Carbon}>  $janelas */
    private static function minutosNasJanelas(array $intervalos, array $janelas): int
    {
        return array_sum(array_map(fn ($j) => self::minutosNaJanela($intervalos, $j[0], $j[1]), $janelas));
    }

    /** @param  list<array{inicio: Carbon, fim: Carbon}>  $intervalos */
    private static function minutosNaJanela(array $intervalos, Carbon $de, Carbon $ate): int
    {
        $total = 0.0;
        foreach ($intervalos as $i) {
            $inicio = $i['inicio']->max($de);
            $fim = $i['fim']->min($ate);
            if ($fim->gt($inicio)) {
                $total += $inicio->diffInMinutes($fim, true);
            }
        }

        return (int) round($total);
    }

    /** Resumo de um lado de fora do expediente (as linhas recolhidas da tela). */
    private static function resumoFora(array $segmentos, array $segmentosFora, array $paradas, array $semSinal, Carbon $de, Carbon $ate): array
    {
        $metros = 0.0;
        $posicoes = 0;
        foreach ($segmentos as $s => $segmento) {
            if (! $segmentosFora[$s] || $segmento[0]['em']->gt($ate) || $segmento[count($segmento) - 1]['em']->lt($de)) {
                continue;
            }
            $metros += self::metros($segmento);
            $posicoes += count($segmento);
        }

        return [
            'posicoes' => $posicoes,
            'distancia_km' => round($metros / 1000, 1),
            'parado_fora_minutos' => self::minutosNaJanela($paradas, $de, $ate),
            'sem_sinal_minutos' => self::minutosNaJanela($semSinal, $de, $ate),
        ];
    }

    /** @param  list<array{lat: float, lng: float}>  $pontos */
    private static function metros(array $pontos): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($pontos); $i++) {
            $total += Haversine::metros($pontos[$i - 1]['lat'], $pontos[$i - 1]['lng'], $pontos[$i]['lat'], $pontos[$i]['lng']);
        }

        return $total;
    }

    /**
     * Deslocamento: os pontos entre uma "âncora" e outra (visita, parada fora de loja), sem buraco
     * de sinal no meio. Começa no último ponto da âncora anterior e termina no primeiro da próxima,
     * pra o horário bater com o checkout/chegada. Com recorte, também corta na borda do expediente.
     *
     * @param  list<array{lat: float, lng: float, em: Carbon}>  $pontos
     * @param  Collection<int, Visita>  $visitas
     * @param  (callable(Carbon): bool)|null  $foraDoExpediente
     * @return list<array<string, mixed>>
     */
    private static function deslocamentos(array $pontos, Collection $visitas, array $paradas, int $tolerancia, ?callable $foraDoExpediente): array
    {
        $ancorado = function (Carbon $em) use ($visitas, $paradas): bool {
            foreach ($visitas as $v) {
                if ($em->gte($v->inicio_data) && $em->lte($v->fim_data ?? now())) {
                    return true;
                }
            }
            foreach ($paradas as $p) {
                if ($em->gte($p['inicio']) && $em->lte($p['fim'])) {
                    return true;
                }
            }

            return false;
        };

        // `$andou`: o trecho tem pelo menos um ponto fora de âncora. Sem isso, dois pontos seguidos
        // da mesma visita (ex.: saiu da loja com a visita aberta — isso é afastamento) virariam trecho.
        $trechos = [];
        $atual = [];
        $andou = false;
        $fecha = function () use (&$atual, &$trechos, &$andou) {
            if ($andou && count($atual) >= 2) {
                $trechos[] = $atual;
            }
            $atual = [];
            $andou = false;
        };

        foreach ($pontos as $i => $p) {
            $anterior = $pontos[$i - 1] ?? null;
            $quebra = $anterior && (
                $anterior['em']->diffInMinutes($p['em'], true) > $tolerancia
                || ($foraDoExpediente && $foraDoExpediente($anterior['em']) !== $foraDoExpediente($p['em']))
            );
            if ($quebra) {
                $fecha();
            }

            if ($ancorado($p['em'])) {
                // Chegou numa âncora: o trecho termina aqui...
                $atual[] = $p;
                $fecha();
                // ...e o próximo começa daqui, quando sair dela.
                $atual = [$p];

                continue;
            }

            $atual[] = $p;
            $andou = true;
        }
        $fecha();

        $resultado = [];
        foreach ($trechos as $trecho) {
            // Âncora pura (só pontos de visita/parada, sem movimento no meio) não é deslocamento.
            $metros = self::metros($trecho);
            if ($metros < self::DESLOCAMENTO_MINIMO_METROS) {
                continue;
            }
            $inicio = $trecho[0]['em'];
            $fim = $trecho[count($trecho) - 1]['em'];
            $resultado[] = [
                'inicio' => $inicio,
                'fim' => $fim,
                'minutos' => (int) round($inicio->diffInMinutes($fim, true)),
                'distancia_metros' => (int) round($metros),
                'fora_expediente' => $foraDoExpediente ? $foraDoExpediente($inicio) && $foraDoExpediente($fim) : false,
                'bairros' => [],
                '_amostras' => self::amostras($trecho),
            ];
        }

        return $resultado;
    }

    /**
     * Fora da área do roteiro: no expediente, fora de qualquer visita, mais longe que o raio de
     * TODAS as lojas do dia, por desvioMinutos ou mais seguidos (buraco de sinal quebra o trecho).
     *
     * @param  list<array{lat: float, lng: float, em: Carbon}>  $pontos
     * @param  Collection<int, Visita>  $visitas
     * @param  list<array{latitude: float, longitude: float}>  $lojas
     * @param  callable(Carbon): bool  $foraDoExpediente
     * @return list<array<string, mixed>>
     */
    private static function desvios(array $pontos, Collection $visitas, array $lojas, int $raio, int $minimoMinutos, int $tolerancia, callable $foraDoExpediente): array
    {
        if ($raio <= 0 || ! $lojas) {
            return [];
        }

        $distanciaDaLoja = function (array $p) use ($lojas): float {
            $menor = INF;
            foreach ($lojas as $l) {
                $menor = min($menor, Haversine::metros($p['lat'], $p['lng'], $l['latitude'], $l['longitude']));
            }

            return $menor;
        };
        $emVisita = function (Carbon $em) use ($visitas): bool {
            foreach ($visitas as $v) {
                if ($em->gte($v->inicio_data) && $em->lte($v->fim_data ?? now())) {
                    return true;
                }
            }

            return false;
        };

        $trechos = [];
        $atual = null;
        foreach ($pontos as $i => $p) {
            $distancia = $foraDoExpediente($p['em']) || $emVisita($p['em']) ? 0.0 : $distanciaDaLoja($p);
            $continua = $atual && $pontos[$i - 1]['em']->diffInMinutes($p['em'], true) <= $tolerancia;
            if ($distancia > $raio) {
                if (! $continua) {
                    if ($atual) {
                        $trechos[] = $atual;
                    }
                    $atual = ['pontos' => [], 'max' => 0.0];
                }
                $atual['pontos'][] = $p;
                $atual['max'] = max($atual['max'], $distancia);
            } elseif ($atual) {
                $trechos[] = $atual;
                $atual = null;
            }
        }
        if ($atual) {
            $trechos[] = $atual;
        }

        $resultado = [];
        foreach ($trechos as $t) {
            $inicio = $t['pontos'][0]['em'];
            $fim = $t['pontos'][count($t['pontos']) - 1]['em'];
            $minutos = $inicio->diffInMinutes($fim, true);
            if ($minutos < $minimoMinutos) {
                continue;
            }
            $resultado[] = [
                'inicio' => $inicio,
                'fim' => $fim,
                'minutos' => (int) round($minutos),
                'distancia_max_metros' => (int) round($t['max']),
                'distancia_metros' => (int) round(self::metros($t['pontos'])),
                'bairros' => [],
                // Pontos brutos (não casados nas ruas), como no afastamento — [lat, lng].
                'pontos' => array_map(fn ($p) => [$p['lat'], $p['lng']], $t['pontos']),
                '_amostras' => self::amostras($t['pontos']),
            ];
        }

        return $resultado;
    }

    /**
     * Lojas do dia: as das Ordens de Serviço do promotor com prazo no dia (a agenda vira OS —
     * docs/10) e as visitadas. Loja da agenda não visitada também conta: ir pra perto dela não é desvio.
     *
     * @param  Collection<int, Visita>  $visitas
     * @return list<array{id: string|null, fantasia: string|null, latitude: float, longitude: float, visitada: bool}>
     */
    private static function lojasDoRoteiro(Usuario $promotor, Carbon $dia, Collection $visitas): array
    {
        $lojas = [];
        foreach ($visitas as $v) {
            $lat = $v->pontoVenda?->latitude ?? $v->inicio_latitude;
            $lng = $v->pontoVenda?->longitude ?? $v->inicio_longitude;
            if ($lat === null || $lng === null) {
                continue;
            }
            $lojas[$v->pontoVenda?->uuid ?? "visita-{$v->uuid}"] = [
                'id' => $v->pontoVenda?->uuid,
                'fantasia' => $v->pontoVenda?->fantasia,
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'visitada' => true,
            ];
        }

        OrdemServico::query()
            ->withoutGlobalScopes()
            ->where('empresa_id', $promotor->empresa_id)
            ->where('usuario_id', $promotor->id)
            ->whereDate('prazo_fim', $dia->toDateString())
            ->where('status', '!=', StatusOrdemServico::CANCELADA)
            ->with(['pontoVenda' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->each(function (OrdemServico $os) use (&$lojas) {
                $loja = $os->pontoVenda;
                if (! $loja || $loja->latitude === null || $loja->longitude === null || isset($lojas[$loja->uuid])) {
                    return;
                }
                $lojas[$loja->uuid] = [
                    'id' => $loja->uuid,
                    'fantasia' => $loja->fantasia,
                    'latitude' => (float) $loja->latitude,
                    'longitude' => (float) $loja->longitude,
                    'visitada' => false,
                ];
            });

        return array_values($lojas);
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $pontos
     * @return list<array{0: float, 1: float}>
     */
    private static function amostras(array $pontos): array
    {
        $n = count($pontos);
        $quantas = min($n, self::AMOSTRAS_BAIRRO);
        $amostras = [];
        for ($k = 0; $k < $quantas; $k++) {
            $p = $pontos[$quantas === 1 ? 0 : (int) round($k * ($n - 1) / ($quantas - 1))];
            $amostras[] = [$p['lat'], $p['lng']];
        }

        return $amostras;
    }

    /** Uma consulta só (em paralelo, com cache) pros bairros de tudo que a tela mostra. */
    private static function preencherBairros(array &$deslocamentos, array &$desvios, array &$paradas): void
    {
        $coordenadas = [];
        foreach ([...$deslocamentos, ...$desvios] as $t) {
            array_push($coordenadas, ...$t['_amostras']);
        }
        foreach ($paradas as $p) {
            $coordenadas[] = [$p['latitude'], $p['longitude']];
        }

        $nomes = $coordenadas ? Bairros::nomes($coordenadas) : [];
        $nomesDe = function (array $amostras) use ($nomes): array {
            $lista = [];
            foreach ($amostras as [$lat, $lng]) {
                $nome = $nomes[Bairros::chave($lat, $lng)] ?? null;
                if ($nome !== null && ! in_array($nome, $lista, true)) {
                    $lista[] = $nome;
                }
            }

            return $lista;
        };

        foreach ($deslocamentos as &$d) {
            $d['bairros'] = $nomesDe($d['_amostras']);
            unset($d['_amostras']);
        }
        unset($d);
        foreach ($desvios as &$d) {
            $d['bairros'] = $nomesDe($d['_amostras']);
            unset($d['_amostras']);
        }
        unset($d);
        foreach ($paradas as &$p) {
            $p['bairro'] = $nomes[Bairros::chave($p['latitude'], $p['longitude'])] ?? null;
        }
        unset($p);
    }

    /**
     * Parada fora de loja: pontos seguidos a até 100 m do primeiro, por paradaMinutos ou mais, sem
     * buraco de sinal no meio, fora de qualquer visita e sem loja cadastrada a até 300 m.
     *
     * @param  list<array{lat: float, lng: float, em: Carbon}>  $pontos
     * @param  Collection<int, Visita>  $visitas
     */
    private static function paradas(array $pontos, Collection $visitas, int $tolerancia, int $paradaMinutos, int $empresaId): array
    {
        $candidatas = [];
        $n = count($pontos);
        $i = 0;
        while ($i < $n) {
            $ancora = $pontos[$i];
            $j = $i;
            while (
                $j + 1 < $n
                && Haversine::metros($ancora['lat'], $ancora['lng'], $pontos[$j + 1]['lat'], $pontos[$j + 1]['lng']) <= self::RAIO_PARADA_METROS
                && $pontos[$j]['em']->diffInMinutes($pontos[$j + 1]['em'], true) <= $tolerancia
            ) {
                $j++;
            }

            $minutos = $ancora['em']->diffInMinutes($pontos[$j]['em'], true);
            if ($j > $i && $minutos >= $paradaMinutos) {
                $grupo = array_slice($pontos, $i, $j - $i + 1);
                $candidatas[] = [
                    'inicio' => $ancora['em'],
                    'fim' => $pontos[$j]['em'],
                    'minutos' => (int) round($minutos),
                    'latitude' => array_sum(array_column($grupo, 'lat')) / count($grupo),
                    'longitude' => array_sum(array_column($grupo, 'lng')) / count($grupo),
                ];
            }
            $i = $j > $i ? $j + 1 : $i + 1;
        }

        if (! $candidatas) {
            return [];
        }

        $lojas = self::lojasPerto($candidatas, $empresaId);

        return array_values(array_filter($candidatas, function (array $c) use ($visitas, $lojas) {
            foreach ($visitas as $v) {
                $inicio = $v->inicio_data->copy()->subMinutes(self::FOLGA_VISITA_MINUTOS);
                $fim = ($v->fim_data ?? now())->copy()->addMinutes(self::FOLGA_VISITA_MINUTOS);
                if ($c['inicio']->lte($fim) && $c['fim']->gte($inicio)) {
                    return false;
                }
            }
            foreach ($lojas as [$lat, $lng]) {
                if (Haversine::metros($c['latitude'], $c['longitude'], $lat, $lng) <= self::RAIO_LOJA_METROS) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<array{0: float, 1: float}> lojas ativas da empresa na região das paradas */
    private static function lojasPerto(array $candidatas, int $empresaId): array
    {
        $margem = 0.01; // ~1 km — só pra não carregar o cadastro inteiro
        $lats = array_column($candidatas, 'latitude');
        $lngs = array_column($candidatas, 'longitude');

        return PontoVenda::query()
            ->withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->whereBetween('latitude', [min($lats) - $margem, max($lats) + $margem])
            ->whereBetween('longitude', [min($lngs) - $margem, max($lngs) + $margem])
            ->get(['latitude', 'longitude'])
            ->map(fn ($p) => [(float) $p->latitude, (float) $p->longitude])
            ->all();
    }

    /**
     * Linha de cada trecho — pelas ruas (Mapbox) quando der, reta quando não. Guardada em
     * `rotas_dia`; `assinatura` muda quando chegam pontos novos, então dia passado nunca mais chama
     * o serviço. Rota aproximada (falha/sem token) não é guardada: tenta de novo na próxima vez.
     *
     * @param  list<list<array{lat: float, lng: float, em: Carbon}>>  $segmentos
     * @return array{linhas: list<list<array{0: float, 1: float}>>, aproximada: bool}
     */
    private static function linhas(Usuario $promotor, Carbon $dia, array $segmentos): array
    {
        if (! $segmentos) {
            return ['linhas' => [], 'aproximada' => false];
        }

        $assinatura = sha1(json_encode(array_map(
            fn ($s) => [count($s), $s[0]['em']->timestamp, $s[count($s) - 1]['em']->timestamp],
            $segmentos,
        )));

        $cache = DB::table('rotas_dia')->where('usuario_id', $promotor->id)->where('data', $dia->toDateString())->first();
        if ($cache && $cache->assinatura === $assinatura) {
            return ['linhas' => json_decode($cache->linhas, true), 'aproximada' => (bool) $cache->aproximada];
        }

        $linhas = [];
        $aproximada = false;
        foreach ($segmentos as $segmento) {
            $reta = array_map(fn ($p) => [$p['lat'], $p['lng']], $segmento);
            if (count($segmento) < 2) {
                $linhas[] = $reta;

                continue;
            }
            $casada = MapMatching::casar(array_map(fn ($p) => ['lat' => $p['lat'], 'lng' => $p['lng'], 't' => $p['em']->timestamp], $segmento));
            if ($casada === null) {
                $aproximada = true;
                $linhas[] = $reta;
            } else {
                $linhas[] = $casada;
            }
        }

        if (! $aproximada) {
            DB::table('rotas_dia')->updateOrInsert(
                ['usuario_id' => $promotor->id, 'data' => $dia->toDateString()],
                ['assinatura' => $assinatura, 'linhas' => json_encode($linhas), 'aproximada' => false, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        return ['linhas' => $linhas, 'aproximada' => $aproximada];
    }
}
