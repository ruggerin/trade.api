<?php

namespace App\Support;

use App\Enums\StatusVisita;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Afastamento durante a visita — docs/49-AFASTAMENTO-DURANTE-VISITA.md: trecho, entre o check-in
 * e o checkout, em que o promotor ficou longe da loja (mais que RASTREAMENTO_AFASTAMENTO_METROS)
 * por RASTREAMENTO_AFASTAMENTO_MINUTOS ou mais, com pelo menos 2 posições seguidas longe (salto de
 * GPS de um ponto só não conta). Buraco sem sinal não vira afastamento — é contado à parte.
 */
final class AfastamentoVisita
{
    /**
     * Calcula a partir do histórico de posições. `$pontos` (ordenados) evita a consulta quando o
     * chamador já tem o dia carregado (Rota do dia). null = não dá pra calcular (sem referência).
     *
     * @param  list<array{lat: float, lng: float, em: Carbon}>|null  $pontos
     * @return array{afastamentos: list<array<string, mixed>>, minutos_fora: int, sem_sinal_minutos: int, sem_dados: bool}|null
     */
    public static function calcular(Visita $visita, ?array $pontos = null): ?array
    {
        [$refLat, $refLng] = self::referencia($visita);
        if ($refLat === null) {
            return null;
        }

        $empresa = $visita->empresa()->withoutGlobalScopes()->first();
        $inicio = $visita->inicio_data;
        $fim = $visita->fim_data ?? now();

        $pontos ??= DB::table('localizacoes_historico')
            ->where('usuario_id', $visita->usuario_id)
            ->whereBetween('capturado_em', [$inicio, $fim])
            ->orderBy('capturado_em')
            ->get(['latitude', 'longitude', 'capturado_em'])
            ->map(fn ($p) => ['lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'em' => Carbon::parse($p->capturado_em)])
            ->all();

        return self::detectar(
            $pontos,
            $refLat,
            $refLng,
            $inicio,
            $fim,
            Rastreamento::afastamentoMetros($empresa),
            Rastreamento::afastamentoMinutos($empresa),
            Rastreamento::toleranciaSemSinalMinutos($empresa),
        );
    }

    /**
     * Saída detectada (docs/92 §3): a visita está ABERTA e o promotor está longe da loja AGORA — o
     * último afastamento não voltou, a posição mais recente é longe e chegou há pouco (dentro da
     * tolerância de sem sinal). null = não saiu (ou não dá pra afirmar).
     *
     * Hora e posição da saída = a última posição ainda perto da loja antes do afastamento (decisão
     * 1): o último momento em que sabemos que ele estava lá. "Perto" = `$raioMetros` (raio do
     * checkout, quando a empresa usa) ou RASTREAMENTO_AFASTAMENTO_METROS. Sem posição perto, usa o
     * check-in. Registro feito depois dessa hora empurra a saída pra hora dele (não encerra antes
     * de algo feito na visita). Mais antiga que o limite do HorarioDoCampo (48 h) não serve.
     *
     * @return array{saiu_em: Carbon, latitude: float, longitude: float, distancia_metros: float, dentro_do_raio: bool|null, distancia_atual_metros: float, minutos_fora: int}|null
     */
    public static function saidaDetectada(Visita $visita, ?int $raioMetros = null, ?Carbon $agora = null): ?array
    {
        if ($visita->status !== StatusVisita::ABERTA) {
            return null;
        }
        [$refLat, $refLng] = self::referencia($visita);
        if ($refLat === null) {
            return null;
        }

        $agora ??= now();
        $empresa = $visita->empresa()->withoutGlobalScopes()->first();
        $metros = Rastreamento::afastamentoMetros($empresa);
        $tolerancia = Rastreamento::toleranciaSemSinalMinutos($empresa);

        $pontos = DB::table('localizacoes_historico')
            ->where('usuario_id', $visita->usuario_id)
            ->whereBetween('capturado_em', [$visita->inicio_data, $agora])
            ->orderBy('capturado_em')
            ->get(['latitude', 'longitude', 'capturado_em'])
            ->map(fn ($p) => ['lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'em' => Carbon::parse($p->capturado_em)])
            ->all();
        if (! $pontos) {
            return null;
        }

        $resultado = self::detectar($pontos, $refLat, $refLng, $visita->inicio_data, $agora, $metros, Rastreamento::afastamentoMinutos($empresa), $tolerancia);
        $afastamento = $resultado['afastamentos'][count($resultado['afastamentos']) - 1] ?? null;
        $ultimo = $pontos[count($pontos) - 1];
        $distanciaAtual = Haversine::metros($refLat, $refLng, $ultimo['lat'], $ultimo['lng']);
        if (
            $afastamento === null
            || $afastamento['fim'] !== null
            || $distanciaAtual <= $metros
            || $ultimo['em']->diffInMinutes($agora, true) > $tolerancia
        ) {
            return null;
        }

        $longeDesde = Carbon::parse($afastamento['inicio']);
        $perto = $raioMetros ?? $metros;
        $referencia = null;
        foreach ($pontos as $p) {
            if ($p['em']->gte($longeDesde)) {
                break;
            }
            $d = Haversine::metros($refLat, $refLng, $p['lat'], $p['lng']);
            if ($d <= $perto) {
                $referencia = [$p, $d];
            }
        }

        if ($referencia) {
            [$p, $distancia] = $referencia;
            [$saiuEm, $lat, $lng] = [$p['em']->copy(), $p['lat'], $p['lng']];
        } else {
            $saiuEm = $visita->inicio_data->copy();
            [$lat, $lng] = [(float) $visita->inicio_latitude, (float) $visita->inicio_longitude];
            $distancia = (float) $visita->inicio_distancia_metros;
        }

        $ultimoRegistro = $visita->registros()->whereNull('cancelado_em')->max('created_at');
        if ($ultimoRegistro !== null && Carbon::parse($ultimoRegistro)->gt($saiuEm)) {
            $saiuEm = Carbon::parse($ultimoRegistro)->min($agora);
        }
        $saiuEm = $saiuEm->max($visita->inicio_data);

        if ($saiuEm->lt($agora->copy()->subHours(HorarioDoCampo::LIMITE_RETROATIVO_HORAS))) {
            return null;
        }

        return [
            'saiu_em' => $saiuEm->utc(),
            'latitude' => $lat,
            'longitude' => $lng,
            'distancia_metros' => $distancia,
            'dentro_do_raio' => $raioMetros === null ? null : $distancia <= $raioMetros,
            'distancia_atual_metros' => $distanciaAtual,
            'minutos_fora' => (int) round($saiuEm->diffInMinutes($agora, true)),
        ];
    }

    /**
     * Fase 2 (doc 49 §6): grava o resumo na visita — alimenta o filtro da lista, o feed do Painel
     * de Atividades e o selo no detalhe. Sem posição nenhuma na janela = colunas nulas (não dá
     * pra afirmar nada), mas marca como calculada pra não tentar de novo.
     */
    public static function gravar(Visita $visita): void
    {
        $resultado = self::calcular($visita);
        $semDados = $resultado === null || $resultado['sem_dados'];

        $visita->forceFill([
            'afastamento_qtd' => $semDados ? null : count($resultado['afastamentos']),
            'afastamento_minutos' => $semDados ? null : $resultado['minutos_fora'],
            'afastamento_max_metros' => $semDados ? null : (int) collect($resultado['afastamentos'])->max('distancia_max_metros'),
            'afastamento_calculado_em' => now(),
        ])->saveQuietly();
    }

    /**
     * @param  list<array{lat: float, lng: float, em: Carbon}>  $pontos
     * @return array{afastamentos: list<array<string, mixed>>, minutos_fora: int, sem_sinal_minutos: int, sem_dados: bool}
     */
    public static function detectar(
        array $pontos,
        float $refLat,
        float $refLng,
        Carbon $inicio,
        Carbon $fim,
        int $metros,
        int $minutosMinimos,
        int $tolerancia,
    ): array {
        $janela = array_values(array_filter($pontos, fn ($p) => $p['em']->gte($inicio) && $p['em']->lte($fim)));
        $n = count($janela);
        $distancias = array_map(fn ($p) => Haversine::metros($refLat, $refLng, $p['lat'], $p['lng']), $janela);
        $gap = fn (Carbon $a, Carbon $b) => $a->diffInMinutes($b, true);

        // Sem sinal dentro da visita: da entrada à 1ª posição, entre posições e da última à saída.
        $semSinal = 0.0;
        $marcos = [$inicio, ...array_column($janela, 'em'), $fim];
        for ($k = 1; $k < count($marcos); $k++) {
            $minutos = $gap($marcos[$k - 1], $marcos[$k]);
            if ($minutos > $tolerancia) {
                $semSinal += $minutos;
            }
        }

        $afastamentos = [];
        $i = 0;
        while ($i < $n) {
            if ($distancias[$i] <= $metros) {
                $i++;

                continue;
            }

            $j = $i;
            while ($j + 1 < $n && $distancias[$j + 1] > $metros && $gap($janela[$j]['em'], $janela[$j + 1]['em']) <= $tolerancia) {
                $j++;
            }

            // Voltou = a posição seguinte já é perto, sem buraco no meio. Senão: acabou a visita
            // longe (fez checkout fora, ou visita aberta) ou o sinal caiu — aí mede até onde há dado.
            $proximo = $janela[$j + 1] ?? null;
            $voltou = $proximo && $gap($janela[$j]['em'], $proximo['em']) <= $tolerancia ? $proximo : null;
            $ate = match (true) {
                $voltou !== null => $voltou['em'],
                $proximo === null && $gap($janela[$j]['em'], $fim) <= $tolerancia => $fim,
                default => $janela[$j]['em'],
            };
            $minutos = $gap($janela[$i]['em'], $ate);

            if ($j > $i && $minutos >= $minutosMinimos) {
                $trecho = array_slice($janela, max(0, $i - 1), $j - max(0, $i - 1) + 1);
                if ($voltou) {
                    $trecho[] = $voltou;
                }
                $afastamentos[] = [
                    'inicio' => $janela[$i]['em']->toIso8601String(),
                    'fim' => $voltou ? $voltou['em']->toIso8601String() : null,
                    'minutos' => (int) round($minutos),
                    'distancia_max_metros' => (int) round(max(array_slice($distancias, $i, $j - $i + 1))),
                    'pontos' => array_map(fn ($p) => [$p['lat'], $p['lng']], $trecho),
                ];
            }

            $i = $j + 1;
        }

        return [
            'afastamentos' => $afastamentos,
            'minutos_fora' => (int) array_sum(array_column($afastamentos, 'minutos')),
            'sem_sinal_minutos' => (int) round($semSinal),
            'sem_dados' => $n === 0,
        ];
    }

    /**
     * Loja; sem coordenada cadastrada, o ponto do check-in — mesma regra do pino da visita na
     * Rota do dia.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function referencia(Visita $visita): array
    {
        $loja = $visita->pontoVenda()->withoutGlobalScopes()->first();
        if ($loja && $loja->latitude !== null && $loja->longitude !== null) {
            return [(float) $loja->latitude, (float) $loja->longitude];
        }
        if ($visita->inicio_latitude !== null && $visita->inicio_longitude !== null) {
            return [(float) $visita->inicio_latitude, (float) $visita->inicio_longitude];
        }

        return [null, null];
    }
}
