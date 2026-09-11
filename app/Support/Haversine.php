<?php

namespace App\Support;

/**
 * Distância em metros entre dois pontos geográficos — usado no check-in (bloqueia fora do
 * raio) e no checkout (só registra, não bloqueia). Ver docs/02-API-BACKEND.md, regra de
 * negócio 1.
 */
class Haversine
{
    private const RAIO_TERRA_METROS = 6371000;

    public static function metros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::RAIO_TERRA_METROS * $c;
    }
}
