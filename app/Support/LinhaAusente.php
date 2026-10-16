<?php

namespace App\Support;

use App\Enums\TipoItemCampanha;
use App\Models\PontoVenda;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use Illuminate\Support\Collection;

/**
 * Escopo de Ação LINHA_AUSENTE (docs/80-MONITORAMENTO-LINHA-AUSENTE.md §5.1): a Ação vale na
 * loja quando nenhum item aprovado do Mix cobre o recorte do TipoRegistro. Mesma regra do app
 * (mobile/src/lib/acaoObrigatoria.ts), que decide offline na visita — aqui serve pra resolver as
 * lojas do "Enviar agora" (TipoRegistroController::lojasDaAcao).
 *
 * Cobre o recorte:
 * - Seção S: produto da seção S, item SECAO = S, ou item DEPARTAMENTO que contém S.
 * - Departamento D: produto do departamento D, item SECAO de uma seção de D, ou item DEPARTAMENTO = D.
 * - Marca M: produto da marca M, ou item MARCA = M.
 * Item pendente/rejeitado não conta; Mix sem nenhum item aprovado não cobra (decisões 2 e 3).
 */
class LinhaAusente
{
    public static function valeNaLoja(TipoRegistro $tipo, PontoVenda $pontoVenda): bool
    {
        $itens = SortimentoPontoVenda::where('ponto_venda_id', $pontoVenda->id)
            ->whereNull('status_aprovacao')
            ->with(['produto:id,secao_id,departamento_id,marca_id', 'secao:id,departamento_id'])
            ->get();

        return self::valeComMix($tipo, $itens);
    }

    /** @param Collection<int, SortimentoPontoVenda> $itensAprovados com `produto` e `secao` carregados */
    public static function valeComMix(TipoRegistro $tipo, Collection $itensAprovados): bool
    {
        if ($itensAprovados->isEmpty()) {
            return false;
        }

        return ! $itensAprovados->contains(fn (SortimentoPontoVenda $item) => self::cobre($tipo, $item));
    }

    /** O item do Mix cobre o recorte do formulário? `produto` e `secao` do item carregados. */
    public static function cobre(TipoRegistro $tipo, SortimentoPontoVenda $item): bool
    {
        $departamentoDaSecao = $tipo->escopoSecao?->departamento_id;
        $produto = $item->produto;

        return match ($tipo->escopo_tipo_vinculo) {
            TipoItemCampanha::SECAO => match ($item->tipo_item) {
                TipoItemCampanha::PRODUTO => $produto?->secao_id === $tipo->escopo_secao_id,
                TipoItemCampanha::SECAO => $item->secao_id === $tipo->escopo_secao_id,
                TipoItemCampanha::DEPARTAMENTO => $departamentoDaSecao !== null && $item->departamento_id === $departamentoDaSecao,
                default => false,
            },
            TipoItemCampanha::DEPARTAMENTO => match ($item->tipo_item) {
                TipoItemCampanha::PRODUTO => $produto?->departamento_id === $tipo->escopo_departamento_id,
                TipoItemCampanha::SECAO => $item->secao?->departamento_id === $tipo->escopo_departamento_id,
                TipoItemCampanha::DEPARTAMENTO => $item->departamento_id === $tipo->escopo_departamento_id,
                default => false,
            },
            TipoItemCampanha::MARCA => match ($item->tipo_item) {
                TipoItemCampanha::PRODUTO => $produto?->marca_id === $tipo->escopo_marca_id,
                TipoItemCampanha::MARCA => $item->marca_id === $tipo->escopo_marca_id,
                default => false,
            },
            // Sem recorte configurado: nada a levantar.
            default => true,
        };
    }
}
