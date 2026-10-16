<?php

namespace App\Relatorios\Entidades;

use App\Models\AcompanhamentoRegistro;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Relatorios\Campo;
use App\Relatorios\Entidade;
use App\Relatorios\Metrica;
use App\Relatorios\Periodo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Ainda está lá?" (docs/83 §3.4): quanto dura o ponto extra, quanto demora a reposição da
 * ruptura, quanto dura a ação da concorrência. Duração = do registro original até o encerramento
 * (ou até hoje, se ainda aberto), em dias.
 */
final class AcompanhamentoEntidade extends Entidade
{
    private const SITUACOES = [
        AcompanhamentoRegistro::ABERTO => 'Aberto',
        AcompanhamentoRegistro::ENCERRADO => 'Encerrado na loja',
        AcompanhamentoRegistro::ENCERRADO_PELO_GESTOR => 'Encerrado pelo gestor',
        AcompanhamentoRegistro::EXPIRADO => 'Expirado',
        AcompanhamentoRegistro::SUBSTITUIDO => 'Substituído',
        AcompanhamentoRegistro::CANCELADO => 'Cancelado',
    ];

    // Encerrado de verdade (alguém confirmou que acabou): é a duração que interessa.
    private const ENCERRADOS = [AcompanhamentoRegistro::ENCERRADO, AcompanhamentoRegistro::ENCERRADO_PELO_GESTOR];

    public function chave(): string
    {
        return 'acompanhamento';
    }

    public function rotulo(): string
    {
        return 'Acompanhamentos ("ainda está lá?")';
    }

    public function consulta(string $campoPeriodo, Carbon $inicio, Carbon $fim): Builder
    {
        return AcompanhamentoRegistro::query()
            ->with(['tipoRegistro:id,uuid,descricao', 'pontoVenda:id,uuid,fantasia,rede_loja_id,uf', 'pontoVenda.redeLoja:id,uuid,descricao', 'registroOrigem:id,created_at'])
            ->whereBetween($campoPeriodo, [$inicio, $fim]);
    }

    public function campos(): array
    {
        return collect([
            new Campo('formulario', 'Formulário', Campo::RELACAO, ['em', 'nao_em'], fonte: 'formularios',
                filtrar: self::filtroRelacao('tipo_registro_id', TipoRegistro::class),
                agrupar: fn (AcompanhamentoRegistro $a) => self::grupoDe($a->tipoRegistro, 'descricao', 'Sem formulário')),
            new Campo('situacao', 'Situação', Campo::ENUM, ['em', 'nao_em'], opcoes: self::SITUACOES,
                filtrar: self::filtroColuna('situacao'),
                agrupar: fn (AcompanhamentoRegistro $a) => [$a->situacao, self::SITUACOES[$a->situacao] ?? $a->situacao]),
            new Campo('loja', 'Loja', Campo::RELACAO, ['em', 'nao_em'], fonte: 'pontos_venda',
                filtrar: self::filtroRelacao('ponto_venda_id', PontoVenda::class),
                agrupar: fn (AcompanhamentoRegistro $a) => self::grupoDe($a->pontoVenda, 'fantasia', 'Sem loja')),
            new Campo('rede', 'Rede', Campo::RELACAO, ['em', 'nao_em'], fonte: 'redes_lojas',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $naRede = fn (Builder $s) => $s->whereHas('pontoVenda', fn (Builder $p) => $p->whereIn('rede_loja_id', RedeLoja::query()->whereIn('uuid', (array) $valor)->select('id')));
                    $operador === 'nao_em' ? $q->whereNot($naRede) : $q->where($naRede);
                },
                agrupar: fn (AcompanhamentoRegistro $a) => self::grupoDe($a->pontoVenda?->redeLoja, 'descricao', 'Sem rede')),
            self::campoUf('pontoVenda', fn (AcompanhamentoRegistro $a) => $a->pontoVenda),
            new Campo('created_at', 'Aberto em', Campo::DATA, periodo: true,
                agrupar: fn (AcompanhamentoRegistro $a, ?string $granularidade, string $tz) => Periodo::agrupar($a->created_at, $granularidade, $tz)),
        ])->keyBy(fn (Campo $c) => $c->chave)->all();
    }

    protected function metricasProprias(): array
    {
        $encerrados = fn (Collection $i) => $i->filter(fn (AcompanhamentoRegistro $a) => in_array($a->situacao, self::ENCERRADOS, true));
        $abertos = fn (Collection $i) => $i->where('situacao', AcompanhamentoRegistro::ABERTO);
        $media = fn (Collection $i) => $i->isEmpty() ? null : round($i->map(fn ($a) => self::dias($a))->avg(), 1);

        return collect([
            new Metrica('acompanhamentos', 'Acompanhamentos', Metrica::INTEIRO, fn (Collection $i) => $i->count()),
            new Metrica('abertos', 'Abertos', Metrica::INTEIRO, fn (Collection $i) => $abertos($i)->count()),
            new Metrica('encerrados', 'Encerrados', Metrica::INTEIRO, fn (Collection $i) => $encerrados($i)->count(),
                'Na loja ("acabou") ou pelo gestor.'),
            new Metrica('duracao_media', 'Duração média (dias)', Metrica::DECIMAL, fn (Collection $i) => $media($encerrados($i)),
                'Do registro até o encerramento. Ex.: quanto durou o ponto extra, quanto demorou a reposição da ruptura.'),
            new Metrica('em_aberto_media', 'Em aberto há (dias, média)', Metrica::DECIMAL, fn (Collection $i) => $media($abertos($i))),
        ])->keyBy(fn (Metrica $m) => $m->chave)->all();
    }

    private static function dias(AcompanhamentoRegistro $a): float
    {
        $inicio = $a->registroOrigem?->created_at ?? $a->created_at;

        return $inicio->diffInHours($a->encerrado_em ?? now(), true) / 24;
    }
}
