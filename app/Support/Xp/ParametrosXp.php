<?php

namespace App\Support\Xp;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Parâmetros da régua de XP (docs/88 §10.4, §12.4, §13) com o default de cada um. Ausente ou
 * inativo = default (mesmo padrão de DirecionamentoParametros). Nada daqui vale com o recurso de
 * plano desligado (`empresas.controle_xp_habilitado`), exceto a avaliação de formulário, que faz
 * sentido sozinha (§2.1).
 */
final class ParametrosXp
{
    public const COMPETENCIA_REGISTRO = 'REGISTRO';

    public const COMPETENCIA_APROVACAO = 'APROVACAO';

    /** Chave => default. A ordem é a da tela de Configurações. */
    public const DEFAULTS = [
        'XP_VISIVEL_PROMOTOR' => 'false',
        'XP_PENDENTE_VISIVEL_PROMOTOR' => 'true',
        'XP_RANKING_HABILITADO' => 'true',
        'XP_COMPETENCIA' => self::COMPETENCIA_APROVACAO,
        'XP_TOLERANCIA_DURACAO_PERCENTUAL' => '20',
        'XP_TEMPO_MINIMO_PERCENTUAL' => '30',
        'XP_LIGA_MINIMO_VISITAS' => '5',
        'AVALIACAO_FORMULARIO_HABILITADA' => 'false',
    ];

    private const VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    /** @var array<int, array<string, string>> cache por requisição */
    private static array $cache = [];

    public static function habilitado(?Empresa $empresa): bool
    {
        return (bool) $empresa?->controle_xp_habilitado;
    }

    /** Régua ligada E liberada pro promotor ver (modo prévia desligado, §13). */
    public static function visivelPromotor(Empresa $empresa): bool
    {
        return self::habilitado($empresa) && self::bool($empresa, 'XP_VISIVEL_PROMOTOR');
    }

    public static function pendenteVisivelPromotor(Empresa $empresa): bool
    {
        return self::bool($empresa, 'XP_PENDENTE_VISIVEL_PROMOTOR');
    }

    public static function rankingHabilitado(Empresa $empresa): bool
    {
        return self::bool($empresa, 'XP_RANKING_HABILITADO');
    }

    /** O recurso de avaliação está ligado (a flag do formulário escolhe quais, §10.1). */
    public static function avaliacaoHabilitada(?Empresa $empresa): bool
    {
        return $empresa !== null && self::bool($empresa, 'AVALIACAO_FORMULARIO_HABILITADA');
    }

    public static function competencia(Empresa $empresa): string
    {
        $valor = strtoupper(self::valor($empresa, 'XP_COMPETENCIA'));

        return $valor === self::COMPETENCIA_REGISTRO ? self::COMPETENCIA_REGISTRO : self::COMPETENCIA_APROVACAO;
    }

    public static function toleranciaInicioMinutos(Empresa $empresa): int
    {
        // Mesma tolerância da Operação do Dia — um "atrasado" só no sistema inteiro.
        $linha = self::linhas($empresa)['ATRASO_TOLERANCIA_MINUTOS'] ?? null;

        return $linha !== null && is_numeric($linha) ? max(0, (int) $linha) : 30;
    }

    public static function toleranciaDuracaoPercentual(Empresa $empresa): int
    {
        return self::inteiro($empresa, 'XP_TOLERANCIA_DURACAO_PERCENTUAL', 0, 300);
    }

    public static function tempoMinimoPercentual(Empresa $empresa): int
    {
        return self::inteiro($empresa, 'XP_TEMPO_MINIMO_PERCENTUAL', 0, 100);
    }

    public static function ligaMinimoVisitas(Empresa $empresa): int
    {
        return self::inteiro($empresa, 'XP_LIGA_MINIMO_VISITAS', 0, 100);
    }

    /** Pra testes e pra quem salvou parâmetro no meio da requisição. */
    public static function limparCache(): void
    {
        self::$cache = [];
    }

    private static function bool(Empresa $empresa, string $chave): bool
    {
        return in_array(strtolower(self::valor($empresa, $chave)), self::VERDADEIROS, true);
    }

    private static function inteiro(Empresa $empresa, string $chave, int $min, int $max): int
    {
        $valor = self::valor($empresa, $chave);
        $numero = is_numeric($valor) ? (int) $valor : (int) self::DEFAULTS[$chave];

        return max($min, min($max, $numero));
    }

    private static function valor(Empresa $empresa, string $chave): string
    {
        return self::linhas($empresa)[$chave] ?? self::DEFAULTS[$chave];
    }

    /** @return array<string, string> só as linhas ativas */
    private static function linhas(Empresa $empresa): array
    {
        return self::$cache[$empresa->id] ??= Parametro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('ativo', true)
            ->where(fn ($q) => $q->where('chave', 'like', 'XP\_%')
                ->orWhereIn('chave', ['AVALIACAO_FORMULARIO_HABILITADA', 'ATRASO_TOLERANCIA_MINUTOS']))
            ->pluck('valor', 'chave')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
