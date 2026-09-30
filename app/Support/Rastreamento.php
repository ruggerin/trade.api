<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;
use Illuminate\Support\Carbon;

/**
 * Rastreamento em tempo real (docs/11-RASTREAMENTO-TEMPO-REAL.md) — configuração por empresa via
 * `Parametro` `RASTREAMENTO_INTERVALO_SEGUNDOS`: ausente/inativo/0 = desligado (opt-in da
 * empresa); >0 = intervalo mínimo, em segundos, entre um envio de posição e outro.
 *
 * Exigência sobre o promotor (docs/47-RASTREAMENTO-EXIGENCIA.md): `RASTREAMENTO_EXIGENCIA`
 * (OPCIONAL/AVISO/OBRIGATORIO), `RASTREAMENTO_SO_NA_JORNADA` e `RASTREAMENTO_PAINEL_CONFORMIDADE`.
 * Defaults iguais ao catálogo de App\Support\ParametrosPadrao.
 */
class Rastreamento
{
    /** Janela em que uma posição ainda conta como "ativo agora" (decisão 3 da doc 11). */
    public const JANELA_ATIVO_MINUTOS = 5;

    public const EXIGENCIAS = ['OPCIONAL', 'AVISO', 'OBRIGATORIO'];

    /** Situações que o app do promotor informa (docs/47 §5.1). */
    public const SITUACOES = [
        'ATIVO',
        'SEM_PERMISSAO',
        'SO_DURANTE_USO',
        'GPS_DESLIGADO',
        'DESLIGADO_PELO_PROMOTOR',
        'NAO_SUPORTADO',
        'FALHA_AO_INICIAR',
    ];

    /** Piso da tolerância "sem sinal" (docs/47 §7 decisão 3). */
    private const TOLERANCIA_SEM_SINAL_MINIMA_MINUTOS = 5;

    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function intervaloSegundos(Empresa $empresa): int
    {
        $valor = self::valor($empresa, 'RASTREAMENTO_INTERVALO_SEGUNDOS');

        return is_numeric($valor) ? max(0, (int) $valor) : 0;
    }

    public static function habilitado(Empresa $empresa): bool
    {
        return self::intervaloSegundos($empresa) > 0;
    }

    /** OPCIONAL (default — comportamento de antes da doc 47), AVISO ou OBRIGATORIO. */
    public static function exigencia(Empresa $empresa): string
    {
        $valor = strtoupper(trim((string) self::valor($empresa, 'RASTREAMENTO_EXIGENCIA')));

        return in_array($valor, self::EXIGENCIAS, true) ? $valor : 'OPCIONAL';
    }

    /** Default true: a exigência (e o "irregular") só vale dentro da jornada. */
    public static function soNaJornada(Empresa $empresa): bool
    {
        $valor = self::valor($empresa, 'RASTREAMENTO_SO_NA_JORNADA');

        return $valor === null || in_array(strtolower(trim($valor)), self::VALORES_VERDADEIROS, true);
    }

    /** Default false: lista de irregulares no Mapa ao vivo só pra empresa que pediu. */
    public static function painelConformidade(Empresa $empresa): bool
    {
        $valor = self::valor($empresa, 'RASTREAMENTO_PAINEL_CONFORMIDADE');

        return $valor !== null && in_array(strtolower(trim($valor)), self::VALORES_VERDADEIROS, true);
    }

    /**
     * Agora está dentro da jornada da empresa (JORNADA_INICIO/FIM, doc 32)? Com
     * RASTREAMENTO_SO_NA_JORNADA=false, o dia inteiro conta como jornada.
     */
    public static function dentroDaJornada(Empresa $empresa, ?Carbon $agora = null): bool
    {
        if (! self::soNaJornada($empresa)) {
            return true;
        }

        $agora ??= now();
        $jornada = OperacaoDoDia::jornada($empresa);
        $hora = $agora->format('H:i');

        return $hora >= $jornada['inicio'] && $hora <= $jornada['fim'];
    }

    /** Quanto tempo sem posição conta como "sem sinal": 3× o intervalo, nunca menos de 5 min. */
    public static function toleranciaSemSinalMinutos(Empresa $empresa): int
    {
        return max(self::TOLERANCIA_SEM_SINAL_MINIMA_MINUTOS, (int) ceil(3 * self::intervaloSegundos($empresa) / 60));
    }

    /** Rota do dia (docs/48): por quantos dias as posições ficam guardadas — default 90, mínimo 1. */
    public static function historicoDias(Empresa $empresa): int
    {
        $valor = self::valor($empresa, 'RASTREAMENTO_HISTORICO_DIAS');

        return is_numeric($valor) && (int) $valor >= 1 ? (int) $valor : 90;
    }

    /** Rota do dia (docs/48): minutos parado no mesmo lugar, sem loja por perto, que contam como parada — default 30. */
    public static function paradaMinutos(Empresa $empresa): int
    {
        $valor = self::valor($empresa, 'RASTREAMENTO_PARADA_MINUTOS');

        return is_numeric($valor) && (int) $valor >= 1 ? (int) $valor : 30;
    }

    /** Valor de um parâmetro ATIVO da empresa, ou null se ausente/inativo. */
    private static function valor(Empresa $empresa, string $chave): ?string
    {
        $parametro = Parametro::query()
            ->withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->first();

        return $parametro?->valor;
    }
}
