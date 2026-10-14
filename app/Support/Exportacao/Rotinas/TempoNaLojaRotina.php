<?php

namespace App\Support\Exportacao\Rotinas;

use App\Enums\Permissao;
use App\Http\Controllers\RelatorioController;
use App\Models\Usuario;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Rotina;
use App\Support\Fuso;

/**
 * Relatório "Tempo na loja" (docs/59 §4.2, docs/61). Não é paginado: o controller devolve as
 * linhas já agrupadas; aqui só vira arquivo, com as mesmas colunas da tela.
 */
final class TempoNaLojaRotina extends Rotina
{
    private const ROTULO_GRUPO = ['loja' => 'Loja', 'promotor' => 'Promotor', 'rede' => 'Rede', 'dia' => 'Dia'];

    public function __construct(private string $agrupar = 'loja')
    {
    }

    public static function chave(): string
    {
        return 'tempo-na-loja';
    }

    public function titulo(): string
    {
        return 'Tempo na loja';
    }

    public function permissao(): Permissao
    {
        return Permissao::TELA_RELATORIOS;
    }

    public function colunas(): array
    {
        return [
            new Coluna('grupo', self::ROTULO_GRUPO[$this->agrupar] ?? 'Loja', $this->agrupar === 'dia' ? 'data' : 'texto'),
            new Coluna('visitas', 'Visitas', 'inteiro'),
            new Coluna('tempo_total', 'Tempo total (min)', 'minutos'),
            new Coluna('media', 'Média (min)', 'minutos'),
            new Coluna('mediana', 'Mediana (min)', 'minutos'),
            new Coluna('itens', 'Itens trabalhados', 'inteiro'),
            new Coluna('min_por_item', 'Minutos por item', 'numero'),
        ];
    }

    public function filtrosPermitidos(): array
    {
        return ['data_inicio', 'data_fim', 'agrupar', 'usuario_uuid', 'ponto_venda_uuid', 'rede_loja_uuid', 'comparar'];
    }

    public function configurar(array $filtros): void
    {
        $agrupar = $filtros['agrupar'] ?? 'loja';
        $this->agrupar = array_key_exists($agrupar, self::ROTULO_GRUPO) ? $agrupar : 'loja';
    }

    public function linhas(array $filtros, Usuario $usuario): iterable
    {
        // Mesmo fuso que a tela usaria: o da empresa, não o do servidor.
        $dados = $this->chamar(RelatorioController::class, 'tempoNaLoja', $filtros + ['tz' => Fuso::daEmpresa($usuario->empresa)], $usuario);

        foreach ($dados['linhas'] ?? [] as $l) {
            yield [
                'grupo' => $l['nome'] ?? null,
                'visitas' => $l['visitas'] ?? null,
                'tempo_total' => $l['tempo_total_minutos'] ?? null,
                'media' => $l['media_minutos'] ?? null,
                'mediana' => $l['mediana_minutos'] ?? null,
                'itens' => $l['itens_trabalhados'] ?? null,
                'min_por_item' => $l['minutos_por_item'] ?? null,
            ];
        }
    }
}
