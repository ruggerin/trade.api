<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Encaixa uma sequência de posições GPS nas ruas (Mapbox Map Matching) — docs/48-ROTA-DO-DIA.md
 * §4.4. Posição a cada ~60s ligada em linha reta corta quarteirão; o serviço devolve o caminho
 * provável pelas ruas. Chamado só pelo backend (token em MAPBOX_TOKEN, nunca vai pro navegador).
 *
 * Qualquer falha (sem token, sem rede, trecho que o Mapbox não consegue casar) devolve null — quem
 * chama cai na linha reta e marca a rota como "aproximada". Nunca derruba a tela.
 */
final class MapMatching
{
    private const URL = 'https://api.mapbox.com/matching/v5/mapbox/driving/';

    /** Limite do Mapbox por chamada. */
    private const MAX_PONTOS = 100;

    /** Leituras a menos disso da anterior (promotor parado) não mudam o desenho — saem antes. */
    private const DISTANCIA_MINIMA_METROS = 10;

    /** Tolerância de cada ponto ao casar (ruído do GPS de celular). */
    private const RAIO_METROS = 35;

    public static function configurado(): bool
    {
        return (string) config('services.mapbox.token') !== '';
    }

    /**
     * @param  list<array{lat: float, lng: float, t: int}>  $pontos  em ordem de horário (t = unix)
     * @return list<array{0: float, 1: float}>|null  linha [lat, lng] seguindo as ruas
     */
    public static function casar(array $pontos): ?array
    {
        if (! self::configurado()) {
            return null;
        }

        $pontos = self::afinar($pontos);
        if (count($pontos) < 2) {
            return null;
        }

        $linha = [];
        // Blocos de até 100 pontos repetindo o último de um bloco como primeiro do próximo, pra
        // linha não ter buraco na emenda.
        for ($inicio = 0; $inicio < count($pontos) - 1; $inicio += self::MAX_PONTOS - 1) {
            $bloco = array_slice($pontos, $inicio, self::MAX_PONTOS);
            if (count($bloco) < 2) {
                break;
            }

            $trecho = self::casarBloco($bloco);
            if ($trecho === null) {
                return null;
            }

            foreach ($trecho as $coord) {
                $ultimo = $linha[count($linha) - 1] ?? null;
                if ($ultimo === null || $ultimo[0] !== $coord[0] || $ultimo[1] !== $coord[1]) {
                    $linha[] = $coord;
                }
            }
        }

        return count($linha) >= 2 ? $linha : null;
    }

    /**
     * @param  list<array{lat: float, lng: float, t: int}>  $bloco
     * @return list<array{0: float, 1: float}>|null
     */
    private static function casarBloco(array $bloco): ?array
    {
        $coordenadas = implode(';', array_map(fn ($p) => sprintf('%.6f,%.6f', $p['lng'], $p['lat']), $bloco));

        try {
            $resposta = Http::timeout((int) config('services.mapbox.timeout', 15))
                ->get(self::URL.$coordenadas, [
                    'access_token' => config('services.mapbox.token'),
                    'geometries' => 'geojson',
                    'overview' => 'full',
                    'tidy' => 'true',
                    'timestamps' => implode(';', array_map(fn ($p) => $p['t'], $bloco)),
                    'radiuses' => implode(';', array_fill(0, count($bloco), self::RAIO_METROS)),
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->ok() || $resposta->json('code') !== 'Ok') {
            return null;
        }

        $linha = [];
        foreach ($resposta->json('matchings') ?? [] as $matching) {
            foreach ($matching['geometry']['coordinates'] ?? [] as [$lng, $lat]) {
                $linha[] = [(float) $lat, (float) $lng];
            }
        }

        return $linha ?: null;
    }

    /**
     * @param  list<array{lat: float, lng: float, t: int}>  $pontos
     * @return list<array{lat: float, lng: float, t: int}>
     */
    private static function afinar(array $pontos): array
    {
        $resultado = [];
        foreach ($pontos as $p) {
            $ultimo = $resultado[count($resultado) - 1] ?? null;
            if ($ultimo === null || Haversine::metros($ultimo['lat'], $ultimo['lng'], $p['lat'], $p['lng']) >= self::DISTANCIA_MINIMA_METROS) {
                $resultado[] = $p;
            }
        }

        // O último ponto sempre entra (é onde o promotor terminou), mesmo parado.
        $ultimoOriginal = $pontos[count($pontos) - 1] ?? null;
        if ($ultimoOriginal !== null && end($resultado) !== $ultimoOriginal) {
            $resultado[] = $ultimoOriginal;
        }

        return $resultado;
    }
}
