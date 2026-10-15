<?php

namespace App\Support;

/** Unidades da federação (docs/78 §5) — sigla da UF da loja. */
final class Uf
{
    public const SIGLAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** " am " → "AM"; vazio → null. Não valida (isso é do `Rule::in(SIGLAS)`). */
    public static function normalizar(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return mb_strtoupper(trim($valor));
    }
}
