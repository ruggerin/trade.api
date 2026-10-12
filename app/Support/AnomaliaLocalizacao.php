<?php

namespace App\Support;

use App\Models\Usuario;
use Carbon\CarbonInterface;

/**
 * Sinais de revezamento que passam pelas travas de login (docs/67 §5.2, Fase 2) e de GPS falso
 * (Fase 3), checados a cada posição recebida. Só registram evento pro ADMIN — nunca bloqueiam o
 * envio (falso positivo em campo custa caro, docs/67 §5.3).
 *
 * - Viagem impossível: da posição anterior pra esta, velocidade acima de VELOCIDADE_MAXIMA_KMH
 *   numa distância relevante (dois aparelhos com a mesma conta em cidades diferentes).
 * - GPS simulado: o próprio Android marca a leitura como "mock" (app de localização falsa).
 *
 * No máximo 1 evento de cada tipo por hora por usuário.
 */
class AnomaliaLocalizacao
{
    public const VELOCIDADE_MAXIMA_KMH = 250;

    /** Abaixo disso é ruído de GPS, não viagem. */
    public const DISTANCIA_MINIMA_METROS = 5000;

    public static function verificar(Usuario $usuario, float $latitude, float $longitude, CarbonInterface $capturadoEm, bool $simulada): void
    {
        if ($simulada && ! Seguranca::registradoRecentemente(Seguranca::LOCALIZACAO_SIMULADA, $usuario, 60)) {
            Seguranca::registrar(Seguranca::LOCALIZACAO_SIMULADA, $usuario, $usuario->dispositivo, [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        $anteriorEm = $usuario->ultima_localizacao_em;
        if ($anteriorEm === null || $usuario->ultima_localizacao_latitude === null || ! $capturadoEm->gt($anteriorEm)) {
            return;
        }

        $metros = Haversine::metros(
            (float) $usuario->ultima_localizacao_latitude,
            (float) $usuario->ultima_localizacao_longitude,
            $latitude,
            $longitude,
        );
        $horas = max($anteriorEm->diffInSeconds($capturadoEm), 1) / 3600;
        $kmh = ($metros / 1000) / $horas;

        if ($metros >= self::DISTANCIA_MINIMA_METROS && $kmh > self::VELOCIDADE_MAXIMA_KMH
            && ! Seguranca::registradoRecentemente(Seguranca::VIAGEM_IMPOSSIVEL, $usuario, 60)) {
            Seguranca::registrar(Seguranca::VIAGEM_IMPOSSIVEL, $usuario, $usuario->dispositivo, [
                'distancia_km' => round($metros / 1000, 1),
                'velocidade_kmh' => (int) round($kmh),
                'de' => [(float) $usuario->ultima_localizacao_latitude, (float) $usuario->ultima_localizacao_longitude],
                'para' => [$latitude, $longitude],
            ]);
        }
    }
}
