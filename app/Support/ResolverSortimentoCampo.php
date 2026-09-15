<?php

namespace App\Support;

use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoItemCampanha;
use App\Models\CampoTipoRegistro;
use App\Models\MarcaDepartamento;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SortimentoPontoVenda;
use Illuminate\Support\Collection;

/**
 * Resolve a lista de produtos de um campo SORTIMENTO pra um PDV — ver
 * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 3. Usado tanto pelo endpoint que o mobile
 * consulta pra montar o checklist (CampoSortimentoController) quanto pela validação de
 * `StoreVisitaRegistroRequest` (cada uuid respondido precisa estar neste conjunto).
 */
class ResolverSortimentoCampo
{
    /** @return Collection<int, ProdutoAuditoria> */
    public static function resolver(CampoTipoRegistro $campo, PontoVenda $pontoVenda): Collection
    {
        return $campo->sortimento_origem === SortimentoOrigemCampo::FIXO
            ? self::resolverFixo($campo)
            : self::resolverDinamico($campo, $pontoVenda);
    }

    /** Lista curada no cadastro do campo — ignora o sortimento real do PDV. */
    private static function resolverFixo(CampoTipoRegistro $campo): Collection
    {
        return $campo->produtosFixos()->with('secao')->where('ativo', true)->get();
    }

    /**
     * Dentro do recorte configurado (seção/departamento/marca), só os produtos que o PDV já tem
     * no próprio sortimento (`SortimentoPontoVenda.tipo_item = PRODUTO`) — mesmo raciocínio de
     * docs/14-SORTIMENTO-PONTO-VENDA.md §9: um item de sortimento cadastrado como seção/
     * departamento/marca inteira ainda não é resolvido em produtos individuais neste sistema,
     * então só entram no checklist os produtos que o próprio PDV já cadastrou individualmente.
     * `status_aprovacao` pendente fica de fora — mesmo raciocínio de
     * CampanhaAuditoriaController::disponiveis (não mostra o que ainda não foi aprovado).
     */
    private static function resolverDinamico(CampoTipoRegistro $campo, PontoVenda $pontoVenda): Collection
    {
        $produtosDoRecorte = match ($campo->sortimento_tipo_vinculo) {
            TipoItemCampanha::SECAO => ProdutoAuditoria::where('secao_id', $campo->sortimento_secao_id)->where('ativo', true)->pluck('id'),
            TipoItemCampanha::DEPARTAMENTO => ProdutoAuditoria::where('departamento_id', $campo->sortimento_departamento_id)->where('ativo', true)->pluck('id'),
            TipoItemCampanha::MARCA => ProdutoAuditoria::whereIn(
                'departamento_id',
                MarcaDepartamento::where('marca_id', $campo->sortimento_marca_id)->pluck('departamento_id'),
            )->where('ativo', true)->pluck('id'),
            default => collect(),
        };

        if ($produtosDoRecorte->isEmpty()) {
            return collect();
        }

        return SortimentoPontoVenda::where('ponto_venda_id', $pontoVenda->id)
            ->where('tipo_item', TipoItemCampanha::PRODUTO)
            ->whereIn('produto_id', $produtosDoRecorte)
            ->whereNull('status_aprovacao')
            ->with('produto.secao')
            ->get()
            ->map(fn (SortimentoPontoVenda $item) => $item->produto)
            ->filter()
            ->unique('id')
            ->values();
    }
}
