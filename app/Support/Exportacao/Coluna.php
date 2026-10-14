<?php

namespace App\Support\Exportacao;

/**
 * Uma coluna do arquivo exportado (docs/68 §4.1). O tipo manda na formatação: o mesmo valor sai
 * como número de verdade no XLSX e como texto formatado no CSV.
 *
 * Tipos: texto · inteiro · numero · moeda · minutos · data · data_hora · booleano.
 * `texto` é o tipo de CPF, CNPJ e códigos — nunca vira número (zero à esquerda preservado).
 */
final class Coluna
{
    public const TIPOS = ['texto', 'inteiro', 'numero', 'moeda', 'minutos', 'data', 'data_hora', 'booleano'];

    public function __construct(
        public readonly string $chave,
        public readonly string $rotulo,
        public readonly string $tipo = 'texto',
    ) {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException("Tipo de coluna inválido: {$tipo}");
        }
    }
}
