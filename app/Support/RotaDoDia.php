<?php

namespace App\Support;

use App\Enums\StatusVisita;
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
 */
final class RotaDoDia
{
    /** Pontos a até isso do primeiro ponto da parada contam como "o mesmo lugar". */
    private const RAIO_PARADA_METROS = 100;

    /** Loja cadastrada a até isso da parada = não é "fora de loja". */
    private const RAIO_LOJA_METROS = 300;

    /** Folga nas bordas da visita (check-in um pouco depois de chegar, checkout antes de sair). */
    private const FOLGA_VISITA_MINUTOS = 5;

    /**
     * `$dia` no fuso da empresa (docs/50 §4.3): o dia vai da meia-noite à meia-noite desse fuso.
     * O recorte vira UTC — é como o instante está no banco, independente do fuso do servidor.
     */
    public static function montar(Usuario $promotor, Carbon $dia): array
    {
        $empresa = $promotor->empresa;
        [$inicioDia, $fimDia] = Fuso::intervaloDoDia($dia->toDateString(), $dia->tzName);

        /** @var list<array{lat: float, lng: float, em: Carbon}> $pontos */
        $pontos = DB::table('localizacoes_historico')
            ->where('usuario_id', $promotor->id)
            ->whereBetween('capturado_em', [$inicioDia, $fimDia])
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

        // Trechos contínuos, separados onde ficou tempo demais sem posição chegar.
        $segmentos = [];
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
                $atual = [];
            }
            $atual[] = $p;
        }
        if ($atual) {
            $segmentos[] = $atual;
        }

        $paradas = self::paradas($pontos, $visitas, $tolerancia, Rastreamento::paradaMinutos($empresa), $promotor->empresa_id);
        ['linhas' => $linhas, 'aproximada' => $aproximada] = self::linhas($promotor, $dia, $segmentos);

        $distancia = 0.0;
        foreach ($segmentos as $segmento) {
            for ($i = 1; $i < count($segmento); $i++) {
                $distancia += Haversine::metros($segmento[$i - 1]['lat'], $segmento[$i - 1]['lng'], $segmento[$i]['lat'], $segmento[$i]['lng']);
            }
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
                'afastamentos' => $afastamento['afastamentos'] ?? [],
                'minutos_fora' => $afastamento['minutos_fora'] ?? 0,
                'sem_sinal_minutos' => $afastamento['sem_sinal_minutos'] ?? 0,
            ];
        });

        return [
            'data' => $dia->toDateString(),
            'parametros' => [
                'parada_minutos' => Rastreamento::paradaMinutos($empresa),
                'afastamento_metros' => Rastreamento::afastamentoMetros($empresa),
                'afastamento_minutos' => Rastreamento::afastamentoMinutos($empresa),
                'tolerancia_sem_sinal_minutos' => $tolerancia,
            ],
            'pontos' => array_map(fn ($p) => ['latitude' => $p['lat'], 'longitude' => $p['lng'], 'em' => $p['em']->toIso8601String()], $pontos),
            'linhas' => $linhas,
            'aproximada' => $aproximada,
            'sem_sinal' => $semSinal,
            'visitas' => $visitasFormatadas,
            'paradas' => $paradas,
            'resumo' => [
                'visitas' => $visitasFormatadas->count(),
                // Tempo em loja honesto (docs/49 §4): desconta o tempo fora durante as visitas.
                'tempo_em_loja_minutos' => max(0, (int) $visitasFormatadas->sum('minutos') - (int) $visitasFormatadas->sum('minutos_fora')),
                'fora_da_loja_minutos' => (int) $visitasFormatadas->sum('minutos_fora'),
                'distancia_km' => round($distancia / 1000, 1),
                'parado_fora_minutos' => (int) collect($paradas)->sum('minutos'),
                'sem_sinal_minutos' => (int) collect($semSinal)->sum('minutos'),
                'primeira_posicao_em' => $pontos ? $pontos[0]['em']->toIso8601String() : null,
                'ultima_posicao_em' => $pontos ? $pontos[count($pontos) - 1]['em']->toIso8601String() : null,
            ],
        ];
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
