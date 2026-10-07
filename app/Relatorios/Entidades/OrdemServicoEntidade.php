<?php

namespace App\Relatorios\Entidades;

use App\Enums\ResponsavelNaoExecucao;
use App\Enums\StatusOrdemServico;
use App\Models\MotivoNaoExecucao;
use App\Models\ObjetivoVisita;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\TipoVisita;
use App\Models\Usuario;
use App\Relatorios\Campo;
use App\Relatorios\Contexto;
use App\Relatorios\Entidade;
use App\Relatorios\Metrica;
use App\Relatorios\Periodo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * O planejado (docs/60 §3.2): ordens de serviço pelo prazo. Mesma classificação da tela fixa
 * "Cumprimento de visitas" (RelatorioController::calcularVisitasPlanejadas) — CANCELADA fica fora
 * do planejado mas é contada à parte, AGUARDANDO_APROVACAO nunca entra.
 */
final class OrdemServicoEntidade extends Entidade
{
    private const STATUS = [
        'PENDENTE' => 'Pendente',
        'EM_ANDAMENTO' => 'Em andamento',
        'CONCLUIDA' => 'Concluída',
        'CANCELADA' => 'Cancelada',
        'REAGENDAMENTO_SOLICITADO' => 'Reagendamento solicitado',
        'CANCELAMENTO_SOLICITADO' => 'Cancelamento solicitado',
    ];

    private const ORIGENS = [
        'MANUAL' => 'Manual',
        'CAMPANHA' => 'Campanha',
        'AGENDA' => 'Agenda',
        'CONTRATO' => 'Contrato',
        'DIRECIONAMENTO' => 'Direcionamento',
    ];

    private const RESPONSAVEIS = [
        'PROMOTOR' => 'Promotor',
        'LOJA' => 'Loja',
        'EMPRESA' => 'Empresa',
        'OUTRO' => 'Outro',
    ];

    public function chave(): string
    {
        return 'ordem_servico';
    }

    public function rotulo(): string
    {
        return 'Visitas planejadas';
    }

    public function consulta(string $campoPeriodo, Carbon $inicio, Carbon $fim): Builder
    {
        return OrdemServico::query()
            ->with([
                'usuario:id,uuid,nome',
                'visita:id,usuario_id',
                'visita.usuario:id,uuid,nome',
                'pontoVenda:id,uuid,fantasia,rede_loja_id',
                'pontoVenda.redeLoja:id,uuid,descricao',
                'tipoVisita:id,uuid,descricao',
                'objetivoVisita:id,uuid,descricao',
                'motivoCancelamento:id,uuid,descricao',
            ])
            ->where('status', '!=', StatusOrdemServico::AGUARDANDO_APROVACAO->value)
            ->whereBetween($campoPeriodo, [$inicio, $fim]);
    }

    public function campos(): array
    {
        return collect([
            new Campo('status', 'Situação', Campo::ENUM, ['em', 'nao_em'], opcoes: self::STATUS,
                filtrar: self::filtroColuna('status'),
                agrupar: fn (OrdemServico $os) => [$os->status->value, self::STATUS[$os->status->value] ?? $os->status->value]),
            new Campo('origem', 'Origem', Campo::ENUM, ['em', 'nao_em'], opcoes: self::ORIGENS,
                filtrar: self::filtroColuna('origem'),
                agrupar: fn (OrdemServico $os) => [$os->origem->value, self::ORIGENS[$os->origem->value] ?? $os->origem->value]),
            // Quem executou manda; sem visita, vale o promotor a quem a OS foi direcionada.
            new Campo('promotor', 'Promotor', Campo::RELACAO, ['em', 'nao_em', 'vazio'], fonte: 'usuarios',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $ids = fn () => Usuario::query()->whereIn('uuid', (array) $valor)->select('id');
                    $casa = fn (Builder $s) => $s->whereIn('usuario_id', $ids())
                        ->orWhereHas('visita', fn (Builder $v) => $v->whereIn('usuario_id', $ids()));
                    match ($operador) {
                        'em' => $q->where($casa),
                        'nao_em' => $q->whereNot($casa),
                        'vazio' => $q->whereNull('usuario_id')->whereDoesntHave('visita'),
                        default => null,
                    };
                },
                agrupar: fn (OrdemServico $os) => self::grupoDe($os->visita?->usuario ?? $os->usuario, 'nome', 'Sem promotor')),
            new Campo('loja', 'Loja', Campo::RELACAO, ['em', 'nao_em'], fonte: 'pontos_venda',
                filtrar: self::filtroRelacao('ponto_venda_id', PontoVenda::class),
                agrupar: fn (OrdemServico $os) => self::grupoDe($os->pontoVenda, 'fantasia', 'Sem loja')),
            new Campo('rede', 'Rede', Campo::RELACAO, ['em', 'nao_em'], fonte: 'redes_lojas',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $naRede = fn (Builder $s) => $s->whereHas('pontoVenda', fn (Builder $p) => $p->whereIn('rede_loja_id', RedeLoja::query()->whereIn('uuid', (array) $valor)->select('id')));
                    $operador === 'nao_em' ? $q->whereNot($naRede) : $q->where($naRede);
                },
                agrupar: fn (OrdemServico $os) => self::grupoDe($os->pontoVenda?->redeLoja, 'descricao', 'Sem rede')),
            new Campo('tipo_visita', 'Tipo de visita', Campo::RELACAO, ['em', 'nao_em', 'vazio', 'nao_vazio'], fonte: 'tipos_visita',
                filtrar: self::filtroRelacao('tipo_visita_id', TipoVisita::class),
                agrupar: fn (OrdemServico $os) => self::grupoDe($os->tipoVisita, 'descricao', 'Sem tipo')),
            new Campo('objetivo_visita', 'Objetivo da visita', Campo::RELACAO, ['em', 'nao_em', 'vazio', 'nao_vazio'], fonte: 'objetivos_visita',
                filtrar: self::filtroRelacao('objetivo_visita_id', ObjetivoVisita::class),
                agrupar: fn (OrdemServico $os) => self::grupoDe($os->objetivoVisita, 'descricao', 'Sem objetivo')),
            new Campo('responsavel_nao_execucao', 'Responsável pela não execução', Campo::ENUM, ['em', 'nao_em', 'vazio', 'nao_vazio'], opcoes: self::RESPONSAVEIS,
                filtrar: self::filtroColuna('responsavel_nao_execucao'),
                agrupar: function (OrdemServico $os) {
                    $v = self::valor($os->responsavel_nao_execucao);

                    return $v ? [$v, self::RESPONSAVEIS[$v] ?? $v] : ['-', 'Não informado'];
                }),
            new Campo('motivo_cancelamento', 'Motivo do cancelamento', Campo::RELACAO, ['em', 'nao_em', 'vazio', 'nao_vazio'], fonte: 'motivos_nao_execucao',
                filtrar: self::filtroRelacao('motivo_cancelamento_id', MotivoNaoExecucao::class),
                agrupar: fn (OrdemServico $os) => self::motivo($os)),
            new Campo('prazo_fim', 'Prazo da visita', Campo::DATA, periodo: true,
                agrupar: fn (OrdemServico $os, ?string $granularidade, string $tz) => Periodo::agrupar($os->prazo_fim, $granularidade, $tz)),
        ])->keyBy(fn (Campo $c) => $c->chave)->all();
    }

    protected function metricasProprias(): array
    {
        $conta = fn (string $categoria) => fn (Collection $itens, Contexto $ctx) => $itens->filter(fn (OrdemServico $os) => self::categoria($os, $ctx) === $categoria)->count();
        $planejadas = fn (Collection $itens, Contexto $ctx) => $itens->reject(fn (OrdemServico $os) => self::categoria($os, $ctx) === 'cancelada')->count();
        $canceladasPromotor = fn (Collection $itens) => $itens->filter(fn (OrdemServico $os) => $os->status === StatusOrdemServico::CANCELADA
            && self::valor($os->responsavel_nao_execucao) === ResponsavelNaoExecucao::PROMOTOR->value)->count();
        $canceladas = fn (Collection $itens) => $itens->filter(fn (OrdemServico $os) => $os->status === StatusOrdemServico::CANCELADA);

        return collect([
            new Metrica('planejadas', 'Planejadas', Metrica::INTEIRO, $planejadas, 'Sem as canceladas e as aguardando aprovação.'),
            new Metrica('executadas', 'Executadas', Metrica::INTEIRO, $conta('executada')),
            new Metrica('em_andamento', 'Em andamento', Metrica::INTEIRO, $conta('em_andamento')),
            new Metrica('atrasadas', 'Atrasadas', Metrica::INTEIRO, $conta('atrasada'), 'Ainda em aberto com o prazo vencido.'),
            new Metrica('a_vencer', 'A vencer', Metrica::INTEIRO, $conta('a_vencer')),
            new Metrica('canceladas', 'Canceladas', Metrica::INTEIRO, fn (Collection $i) => $canceladas($i)->count()),
            new Metrica('canceladas_promotor', 'Canceladas pelo promotor', Metrica::INTEIRO, $canceladasPromotor),
            new Metrica('cumprimento', 'Cumprimento', Metrica::PERCENTUAL,
                fn (Collection $i, Contexto $ctx) => self::percentual($conta('executada')($i, $ctx), $planejadas($i, $ctx)),
                'Executadas sobre planejadas.'),
            new Metrica('cumprimento_ajustado', 'Cumprimento ajustado', Metrica::PERCENTUAL,
                fn (Collection $i, Contexto $ctx) => self::percentual($conta('executada')($i, $ctx), $planejadas($i, $ctx) + $canceladasPromotor($i)),
                'Cancelar por culpa do promotor conta como não executada.'),
            new Metrica('canceladas_por_responsavel', 'Canceladas por responsável', Metrica::DISTRIBUICAO,
                fn (Collection $i) => self::distribuicao($canceladas($i), function (OrdemServico $os) {
                    $v = self::valor($os->responsavel_nao_execucao);

                    return $v ? [$v, self::RESPONSAVEIS[$v] ?? $v] : ['NAO_INFORMADO', 'Não informado'];
                })),
            new Metrica('canceladas_por_motivo', 'Canceladas por motivo', Metrica::DISTRIBUICAO,
                fn (Collection $i) => self::distribuicao($canceladas($i), fn (OrdemServico $os) => self::motivo($os))),
        ])->keyBy(fn (Metrica $m) => $m->chave)->all();
    }

    /** cancelada | executada | em_andamento | atrasada | a_vencer */
    private static function categoria(OrdemServico $os, Contexto $ctx): string
    {
        return match ($os->status) {
            StatusOrdemServico::CANCELADA => 'cancelada',
            StatusOrdemServico::CONCLUIDA => 'executada',
            StatusOrdemServico::EM_ANDAMENTO => 'em_andamento',
            default => $os->prazo_fim->lt($ctx->agora) ? 'atrasada' : 'a_vencer',
        };
    }

    /** @return array{0: string, 1: string} — mesmo rótulo da tela fixa. */
    private static function motivo(OrdemServico $os): array
    {
        if ($os->motivoCancelamento) {
            return [$os->motivoCancelamento->uuid, $os->motivoCancelamento->descricao];
        }

        return $os->motivo_cancelamento_texto ? ['OUTRO', 'Outro (texto livre)'] : ['-', 'Sem motivo informado'];
    }
}
