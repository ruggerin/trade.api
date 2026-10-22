<?php

namespace App\Support;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Nome do bairro de uma posição (Mapbox reverse geocoding) — docs/93: "passou por Tarumã, Colônia
 * Terra Nova" no deslocamento e no desvio da Rota do dia. Mesmo token do MapMatching, só backend.
 *
 * A coordenada é arredondada numa grade de ~500 m e o nome fica em cache por 90 dias: bairro não
 * muda e a mesma grade se repete todo dia. Falha (sem token, sem rede) devolve null e
 * não é guardada — quem chama mostra sem o bairro e tenta de novo na próxima vez.
 */
final class Bairros
{
    private const URL = 'https://api.mapbox.com/geocoding/v5/mapbox.places/';

    /** Grade de ~550 m (1/200 de grau): bom pra bairro e aproveita o cache. */
    private const GRADE = 200;

    /** Teto de consultas novas por tela — o resto aparece na próxima abertura, já com cache. */
    private const MAX_CONSULTAS = 60;

    private const CACHE_DIAS = 90;

    /**
     * @param  list<array{0: float, 1: float}>  $coordenadas  [lat, lng]
     * @return array<string, string|null> chave da grade => nome do bairro
     */
    public static function nomes(array $coordenadas): array
    {
        $chaves = [];
        foreach ($coordenadas as [$lat, $lng]) {
            $chaves[self::chave($lat, $lng)] = [round($lat * self::GRADE) / self::GRADE, round($lng * self::GRADE) / self::GRADE];
        }

        $nomes = [];
        $faltando = [];
        foreach ($chaves as $chave => $coord) {
            $nome = Cache::get("bairro:{$chave}");
            if ($nome !== null) {
                $nomes[$chave] = $nome === '' ? null : $nome;
            } elseif (count($faltando) < self::MAX_CONSULTAS) {
                $faltando[$chave] = $coord;
            } else {
                $nomes[$chave] = null;
            }
        }

        if ($faltando && (string) config('services.mapbox.token') !== '') {
            try {
                $respostas = Http::pool(fn (Pool $pool) => array_map(
                    fn ($chave) => $pool->as($chave)->timeout(5)->get(self::URL.sprintf('%.5f,%.5f.json', $faltando[$chave][1], $faltando[$chave][0]), [
                        'access_token' => config('services.mapbox.token'),
                        'types' => 'neighborhood,locality,district,place',
                        'language' => 'pt',
                    ]),
                    array_keys($faltando),
                ));
            } catch (Throwable) {
                $respostas = [];
            }

            foreach (array_keys($faltando) as $chave) {
                $resposta = $respostas[$chave] ?? null;
                if (! $resposta instanceof Response || ! $resposta->ok()) {
                    $nomes[$chave] = null;

                    continue;
                }
                $nome = self::nomeDaResposta($resposta);
                // Respondeu sem bairro (área rural, rio): guarda o "sem nome" também, pra não perguntar de novo.
                Cache::put("bairro:{$chave}", $nome ?? '', now()->addDays(self::CACHE_DIAS));
                $nomes[$chave] = $nome;
            }
        }

        foreach (array_keys($chaves) as $chave) {
            $nomes[$chave] ??= null;
        }

        return $nomes;
    }

    public static function chave(float $lat, float $lng): string
    {
        return round($lat * self::GRADE).':'.round($lng * self::GRADE);
    }

    /** O tipo mais "de bairro" que vier (neighborhood > locality > district > place). */
    private static function nomeDaResposta(Response $resposta): ?string
    {
        $porTipo = [];
        foreach ($resposta->json('features') ?? [] as $feature) {
            foreach ($feature['place_type'] ?? [] as $tipo) {
                $porTipo[$tipo] ??= $feature['text'] ?? null;
            }
        }

        foreach (['neighborhood', 'locality', 'district', 'place'] as $tipo) {
            if (! empty($porTipo[$tipo])) {
                return $porTipo[$tipo];
            }
        }

        return null;
    }
}
