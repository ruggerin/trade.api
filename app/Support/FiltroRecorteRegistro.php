<?php

namespace App\Support;

use App\Models\SecaoAuditoria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro de registros (VisitaRegistro) por seção/departamento/marca respeitando a hierarquia do
 * catálogo — docs/79 decisão 10. O registro é gravado uma vez só, no recorte escolhido (produto,
 * seção, departamento ou marca); o filtro é que sobe a hierarquia:
 *
 * - Seção S: registros na seção S ou de um produto dela.
 * - Departamento D: registros no departamento D, numa seção de D ou de um produto de D.
 * - Marca M: registros na marca M ou de um produto dela. Departamento/seção não entram: o mesmo
 *   departamento é compartilhado por várias marcas (MarcaDepartamento), e entrariam registros que
 *   não são da marca.
 *
 * `$ids` = ids (array ou subquery de ids) do recorte.
 */
class FiltroRecorteRegistro
{
    public static function secao(Builder $q, mixed $ids): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereIn('secao_id', $ids)
            ->orWhereHas('produtoAuditoria', fn (Builder $p) => $p->whereIn('secao_id', $ids)));
    }

    public static function departamento(Builder $q, mixed $ids): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereIn('departamento_id', $ids)
            ->orWhereIn('secao_id', SecaoAuditoria::withoutGlobalScopes()->whereIn('departamento_id', $ids)->select('id'))
            ->orWhereHas('produtoAuditoria', fn (Builder $p) => $p->whereIn('departamento_id', $ids)));
    }

    public static function marca(Builder $q, mixed $ids): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereIn('marca_id', $ids)
            ->orWhereHas('produtoAuditoria', fn (Builder $p) => $p->whereIn('marca_id', $ids)));
    }
}
