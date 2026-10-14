<?php

namespace App\Support\Exportacao;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Regras únicas de formatação do arquivo (docs/68 §4.2) — datas no fuso da empresa, vazio é vazio
 * (nunca zero), minutos como número. Os dois escritores (CSV e XLSX) usam as mesmas funções.
 */
final class Formatador
{
    public function __construct(private readonly string $fuso)
    {
    }

    /** Valor bruto → Carbon no fuso da empresa, ou null. Data "pura" (AAAA-MM-DD) não troca de fuso. */
    private function carbon(mixed $valor, bool $comHora): ?CarbonInterface
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        try {
            if ($valor instanceof CarbonInterface) {
                return $valor->copy()->setTimezone($this->fuso);
            }
            $texto = (string) $valor;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
                return Carbon::createFromFormat('Y-m-d', $texto, $this->fuso)->startOfDay();
            }
            // Instantes vêm em UTC (docs/50 §2): converter pro fuso da empresa antes de formatar.
            return Carbon::parse($texto, 'UTC')->setTimezone($this->fuso);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Texto do CSV e do PDF. */
    public function texto(Coluna $coluna, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($coluna->tipo) {
            'data' => $this->carbon($valor, false)?->format('d/m/Y') ?? (string) $valor,
            'data_hora' => $this->carbon($valor, true)?->format('d/m/Y H:i') ?? (string) $valor,
            'booleano' => $valor ? 'Sim' : 'Não',
            'inteiro', 'minutos' => (string) (int) round((float) $valor),
            'numero' => $this->decimal((float) $valor, 1),
            'moeda' => $this->decimal((float) $valor, 2),
            default => $this->neutralizarFormula((string) $valor),
        };
    }

    /**
     * Valor tipado do XLSX: [tipo, valor] com tipo "n" (número), "s" (texto) ou "vazio". Data e hora
     * viram número serial do Excel (a célula recebe o formato de data pelo estilo).
     *
     * @return array{0: string, 1: float|string|null}
     */
    public function excel(Coluna $coluna, mixed $valor): array
    {
        if ($valor === null || $valor === '') {
            return ['vazio', null];
        }

        switch ($coluna->tipo) {
            case 'data':
            case 'data_hora':
                $c = $this->carbon($valor, $coluna->tipo === 'data_hora');
                if (! $c) {
                    return ['s', (string) $valor];
                }
                // Serial do Excel: dias desde 30/12/1899, no horário LOCAL (sem fuso na célula).
                $local = Carbon::create($c->year, $c->month, $c->day, $coluna->tipo === 'data_hora' ? $c->hour : 0, $coluna->tipo === 'data_hora' ? $c->minute : 0, 0, 'UTC');

                return ['n', $local->getTimestamp() / 86400 + 25569];
            case 'booleano':
                return ['s', $valor ? 'Sim' : 'Não'];
            case 'inteiro':
            case 'minutos':
                return ['n', (float) round((float) $valor)];
            case 'numero':
            case 'moeda':
                return ['n', (float) $valor];
            default:
                return ['s', (string) $valor];
        }
    }

    private function decimal(float $v, int $casas): string
    {
        return number_format($v, $casas, ',', '');
    }

    /**
     * Planilha interpreta célula que começa com = + - @ como fórmula (injeção de CSV). Texto vindo
     * de usuário recebe um apóstrofo na frente — o Excel o esconde e não executa nada.
     */
    private function neutralizarFormula(string $texto): string
    {
        return $texto !== '' && preg_match('/^[=+\-@\t\r]/', $texto) ? "'".$texto : $texto;
    }
}
