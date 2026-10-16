<?php

namespace App\Enums;

/**
 * Unidade da embalagem no registro por marca concorrente (docs/85 §4.1). `ml`/`g` normalizam pra
 * litro/quilo no cálculo do R$ por litro/kg; `UN` não tem preço por unidade de medida.
 */
enum UnidadeEmbalagem: string
{
    case ML = 'ML';
    case L = 'L';
    case G = 'G';
    case KG = 'KG';
    case UN = 'UN';

    /** Quantidade na unidade de referência (litro ou quilo); null pra unidade avulsa. */
    public function emReferencia(float $quantidade): ?float
    {
        return match ($this) {
            self::ML, self::G => $quantidade / 1000,
            self::L, self::KG => $quantidade,
            self::UN => null,
        };
    }

    /** "L" ou "kg" — rótulo do preço por unidade de medida. */
    public function referencia(): ?string
    {
        return match ($this) {
            self::ML, self::L => 'L',
            self::G, self::KG => 'kg',
            self::UN => null,
        };
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::ML => 'ml',
            self::L => 'L',
            self::G => 'g',
            self::KG => 'kg',
            self::UN => 'un',
        };
    }

    /** "500 ml", "1,5 L". */
    public static function descrever(?float $quantidade, ?string $unidade): ?string
    {
        $u = $unidade ? self::tryFrom($unidade) : null;
        if ($quantidade === null || $u === null) {
            return null;
        }
        $numero = rtrim(rtrim(number_format($quantidade, 3, ',', '.'), '0'), ',');

        return "{$numero} {$u->rotulo()}";
    }
}
