<?php

namespace App\Relatorios\Entidades;

use App\Enums\StatusVisita;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\Usuario;
use App\Models\Visita;
use App\Relatorios\Campo;
use App\Relatorios\Entidade;
use App\Relatorios\Metrica;
use App\Relatorios\Periodo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * O executado e o tempo na loja (docs/60 §3.2). Tempo efetivo = duração da visita menos o
 * afastamento (docs/49), só de visitas válidas — finalizadas, não canceladas, de 2 min a 12 h. A
 * mesma regra da tela fixa "Tempo no PDV" (RelatorioController::calcularTempoNaLoja).
 */
final class VisitaEntidade extends Entidade
{
    public const TEMPO_MIN_VALIDO = 2;

    public const TEMPO_MAX_VALIDO = 720;

    private const STATUS = [
        'ABERTA' => 'Em andamento',
        'FINALIZADA' => 'Finalizada',
        'CANCELADA' => 'Cancelada',
    ];

    private const CHECKOUT = [
        'PROMOTOR' => 'Pelo promotor',
        'ADMIN' => 'Pelo gestor',
    ];

    public function chave(): string
    {
        return 'visita';
    }

    public function rotulo(): string
    {
        return 'Visitas realizadas';
    }

    public function consulta(string $campoPeriodo, Carbon $inicio, Carbon $fim): Builder
    {
        return Visita::query()
            ->with(['pontoVenda:id,uuid,fantasia,rede_loja_id', 'pontoVenda.redeLoja:id,uuid,descricao', 'usuario:id,uuid,nome'])
            ->whereBetween($campoPeriodo, [$inicio, $fim]);
    }

    public function campos(): array
    {
        return collect([
            new Campo('status', 'Situação', Campo::ENUM, ['em', 'nao_em'], opcoes: self::STATUS,
                filtrar: self::filtroColuna('status'),
                agrupar: fn (Visita $v) => [$v->status->value, self::STATUS[$v->status->value] ?? $v->status->value]),
            new Campo('promotor', 'Promotor', Campo::RELACAO, ['em', 'nao_em'], fonte: 'usuarios',
                filtrar: self::filtroRelacao('usuario_id', Usuario::class),
                agrupar: fn (Visita $v) => self::grupoDe($v->usuario, 'nome', 'Sem promotor')),
            new Campo('loja', 'Loja', Campo::RELACAO, ['em', 'nao_em'], fonte: 'pontos_venda',
                filtrar: self::filtroRelacao('ponto_venda_id', PontoVenda::class),
                agrupar: fn (Visita $v) => self::grupoDe($v->pontoVenda, 'fantasia', 'Sem loja')),
            new Campo('rede', 'Rede', Campo::RELACAO, ['em', 'nao_em'], fonte: 'redes_lojas',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $naRede = fn (Builder $s) => $s->whereHas('pontoVenda', fn (Builder $p) => $p->whereIn('rede_loja_id', RedeLoja::query()->whereIn('uuid', (array) $valor)->select('id')));
                    $operador === 'nao_em' ? $q->whereNot($naRede) : $q->where($naRede);
                },
                agrupar: fn (Visita $v) => self::grupoDe($v->pontoVenda?->redeLoja, 'descricao', 'Sem rede')),
            new Campo('checkout_tipo', 'Checkout feito', Campo::ENUM, ['em', 'nao_em', 'vazio', 'nao_vazio'], opcoes: self::CHECKOUT,
                filtrar: self::filtroColuna('checkout_tipo'),
                agrupar: function (Visita $v) {
                    $tipo = self::valor($v->checkout_tipo);

                    return $tipo ? [$tipo, self::CHECKOUT[$tipo] ?? $tipo] : ['-', 'Sem checkout'];
                }),
            // Visita sem ordem de serviço: o promotor foi por conta própria.
            new Campo('espontanea', 'Espontânea', Campo::BOOLEANO, ['igual'],
                filtrar: fn (Builder $q, string $operador, mixed $valor) => filter_var($valor, FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNull('ordem_servico_id')
                    : $q->whereNotNull('ordem_servico_id'),
                agrupar: fn (Visita $v) => $v->ordem_servico_id === null ? ['sim', 'Espontânea'] : ['nao', 'Planejada']),
            new Campo('inicio_data', 'Início da visita', Campo::DATA, periodo: true,
                agrupar: fn (Visita $v, ?string $granularidade, string $tz) => Periodo::agrupar($v->inicio_data, $granularidade, $tz)),
        ])->keyBy(fn (Campo $c) => $c->chave)->all();
    }

    protected function metricasProprias(): array
    {
        $validos = fn (Collection $itens) => $itens->map(fn (Visita $v) => self::minutosEfetivos($v))->filter(fn ($m) => $m !== null)->values();

        return collect([
            new Metrica('visitas', 'Visitas', Metrica::INTEIRO, fn (Collection $i) => $validos($i)->count(),
                'Finalizadas, de 2 min a 12 h.'),
            new Metrica('tempo_total', 'Tempo total', Metrica::MINUTOS, fn (Collection $i) => (int) $validos($i)->sum()),
            new Metrica('tempo_medio', 'Tempo médio', Metrica::MINUTOS,
                fn (Collection $i) => $validos($i)->isEmpty() ? null : (int) round($validos($i)->avg())),
            new Metrica('tempo_mediano', 'Tempo mediano', Metrica::MINUTOS, fn (Collection $i) => self::mediana($validos($i))),
            new Metrica('desconsideradas', 'Desconsideradas', Metrica::INTEIRO,
                fn (Collection $i) => $i->filter(fn (Visita $v) => self::desconsiderada($v))->count(),
                'Finalizadas com menos de 2 min ou mais de 12 h.'),
        ])->keyBy(fn (Metrica $m) => $m->chave)->all();
    }

    /** Duração menos afastamento; null quando a visita não entra na conta de tempo. */
    public static function minutosEfetivos(Visita $v): ?int
    {
        $duracao = self::duracao($v);
        if ($duracao === null || $duracao < self::TEMPO_MIN_VALIDO || $duracao > self::TEMPO_MAX_VALIDO) {
            return null;
        }

        return max(0, $duracao - (int) ($v->afastamento_minutos ?? 0));
    }

    private static function desconsiderada(Visita $v): bool
    {
        $duracao = self::duracao($v);

        return $duracao !== null && ($duracao < self::TEMPO_MIN_VALIDO || $duracao > self::TEMPO_MAX_VALIDO);
    }

    /** Duração em minutos de visita finalizada e não cancelada; senão null. */
    private static function duracao(Visita $v): ?int
    {
        if ($v->status === StatusVisita::CANCELADA || $v->fim_data === null || $v->inicio_data === null) {
            return null;
        }

        return (int) round($v->inicio_data->diffInSeconds($v->fim_data) / 60);
    }

    private static function mediana(Collection $valores): ?int
    {
        if ($valores->isEmpty()) {
            return null;
        }
        $ordenados = $valores->sort()->values();
        $meio = intdiv($ordenados->count(), 2);

        return (int) round($ordenados->count() % 2 ? $ordenados[$meio] : ($ordenados[$meio - 1] + $ordenados[$meio]) / 2);
    }
}
