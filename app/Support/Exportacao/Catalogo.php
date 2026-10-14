<?php

namespace App\Support\Exportacao;

use App\Support\Exportacao\Rotinas\LojasRotina;
use App\Support\Exportacao\Rotinas\OrdensServicoRotina;
use App\Support\Exportacao\Rotinas\RegistrosRotina;
use App\Support\Exportacao\Rotinas\TempoNaLojaRotina;
use App\Support\Exportacao\Rotinas\VisitasRotina;

/** Rotinas exportáveis (docs/68 §5). Rotina nova entra aqui e ganha o botão "Exportar" na tela. */
final class Catalogo
{
    /** @var list<class-string<Rotina>> */
    private const ROTINAS = [
        RegistrosRotina::class,
        VisitasRotina::class,
        LojasRotina::class,
        OrdensServicoRotina::class,
        TempoNaLojaRotina::class,
    ];

    public static function achar(string $chave): ?Rotina
    {
        foreach (self::ROTINAS as $classe) {
            if ($classe::chave() === $chave) {
                return new $classe;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_map(fn (string $c) => $c::chave(), self::ROTINAS);
    }
}
