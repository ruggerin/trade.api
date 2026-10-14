<?php

namespace App\Support\Exportacao\Rotinas;

use App\Enums\Permissao;
use App\Http\Controllers\OrdemServicoController;
use App\Models\Usuario;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Rotina;

/**
 * Ordens de serviço — cobre a tela de OS e a de "Visitas não realizadas" (docs/59: o mesmo
 * endpoint com `vencidas=1`), com o rastro de cancelamento quando existe.
 */
final class OrdensServicoRotina extends Rotina
{
    public static function chave(): string
    {
        return 'ordens-servico';
    }

    public function titulo(): string
    {
        return 'Ordens de serviço';
    }

    public function permissao(): Permissao
    {
        return Permissao::TELA_ORDENS_SERVICO;
    }

    public function colunas(): array
    {
        return [
            new Coluna('loja', 'Loja'),
            new Coluna('promotor', 'Promotor'),
            new Coluna('origem', 'Origem'),
            new Coluna('status', 'Status'),
            new Coluna('prazo_inicio', 'Prazo — início', 'data_hora'),
            new Coluna('prazo_fim', 'Prazo — fim', 'data_hora'),
            new Coluna('prioridade', 'Prioridade'),
            new Coluna('obrigatoria', 'Obrigatória', 'booleano'),
            new Coluna('campanha', 'Campanha'),
            new Coluna('observacao', 'Observação'),
            new Coluna('cancelada_em', 'Cancelada em', 'data_hora'),
            new Coluna('cancelada_por', 'Cancelada por'),
            new Coluna('responsavel', 'Responsável pela não execução'),
            new Coluna('motivo', 'Motivo'),
        ];
    }

    public function filtrosPermitidos(): array
    {
        return ['status', 'vencidas', 'origem', 'prazo_de', 'prazo_ate', 'ponto_venda_uuid', 'usuario_uuid'];
    }

    public function linhas(array $filtros, Usuario $usuario): iterable
    {
        foreach ($this->paginar(OrdemServicoController::class, 'index', 'ordens_servico', $filtros, $usuario) as $o) {
            yield [
                'loja' => self::pegar($o, 'ponto_venda.fantasia'),
                'promotor' => self::pegar($o, 'usuario.nome'),
                'origem' => $o['origem'] ?? null,
                'status' => $o['status'] ?? null,
                'prazo_inicio' => $o['prazo_inicio'] ?? null,
                'prazo_fim' => $o['prazo_fim'] ?? null,
                'prioridade' => $o['prioridade'] ?? null,
                'obrigatoria' => isset($o['obrigatoria']) ? (bool) $o['obrigatoria'] : null,
                'campanha' => self::pegar($o, 'campanha.descricao'),
                'observacao' => $o['observacao'] ?? null,
                'cancelada_em' => $o['cancelada_em'] ?? null,
                'cancelada_por' => self::pegar($o, 'cancelada_por.nome'),
                'responsavel' => $o['responsavel_nao_execucao'] ?? null,
                'motivo' => self::pegar($o, 'motivo_cancelamento.descricao') ?? ($o['motivo_cancelamento_texto'] ?? null),
            ];
        }
    }
}
