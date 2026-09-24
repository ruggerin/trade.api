<?php

namespace App\Support;

use App\Enums\TipoCampoRegistro;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use Illuminate\Support\Collection;

/**
 * Formata `VisitaRegistro.valores_campos` (bruto, chave => string — ex.:
 * `{"quantidade":"5","mix_produtos":"{\"presentes\":[uuid,...],\"ausentes\":[uuid,...]}"}`) pra
 * exibição: resolve o `rotulo` de cada campo (via `TipoRegistro.campos`, já precisa vir
 * eager-loaded) e formata o valor conforme `tipo_campo` — `BOOLEANO` vira "Sim"/"Não",
 * `SORTIMENTO` resolve os uuids de produto pra descrição (1 query só, não 1 por campo). Usado por
 * `VisitaRegistroResource` pro detalhe de visita no admin (docs/34-REMODELACAO-VISITA-DETALHE-ADMIN.md).
 */
class FormatadorValoresCampos
{
    /**
     * @return list<array{chave: string, rotulo: string, tipo_campo: string, valor: string|null, sortimento: array{presentes: list<array{id: string, descricao: string}>, ausentes: list<array{id: string, descricao: string}>}|null}>
     */
    public static function formatar(TipoRegistro $tipoRegistro, ?array $valoresCampos): array
    {
        if (! $valoresCampos || ! $tipoRegistro->relationLoaded('campos')) {
            return [];
        }

        $campos = $tipoRegistro->campos->sortBy('ordem');
        $produtosPorUuid = self::resolverProdutosSortimento($campos, $valoresCampos);

        $linhas = [];
        foreach ($campos as $campo) {
            $bruto = $valoresCampos[$campo->chave] ?? null;
            if ($bruto === null || $bruto === '') {
                continue;
            }

            if ($campo->tipo_campo === TipoCampoRegistro::SORTIMENTO) {
                $linhas[] = [
                    'chave' => $campo->chave,
                    'rotulo' => $campo->rotulo,
                    'tipo_campo' => $campo->tipo_campo->value,
                    'valor' => null,
                    'sortimento' => self::montarSortimento($bruto, $produtosPorUuid),
                ];

                continue;
            }

            $linhas[] = [
                'chave' => $campo->chave,
                'rotulo' => $campo->rotulo,
                'tipo_campo' => $campo->tipo_campo->value,
                'valor' => $campo->tipo_campo === TipoCampoRegistro::BOOLEANO ? ($bruto === '1' ? 'Sim' : 'Não') : $bruto,
                'sortimento' => null,
            ];
        }

        return $linhas;
    }

    /** @param  Collection<int, \App\Models\CampoTipoRegistro>  $campos */
    private static function resolverProdutosSortimento(Collection $campos, array $valoresCampos): Collection
    {
        $uuids = collect();

        foreach ($campos->where('tipo_campo', TipoCampoRegistro::SORTIMENTO) as $campo) {
            $decodificado = json_decode($valoresCampos[$campo->chave] ?? '', true);
            if (! is_array($decodificado)) {
                continue;
            }
            $uuids = $uuids->concat($decodificado['presentes'] ?? [])->concat($decodificado['ausentes'] ?? []);
        }

        if ($uuids->isEmpty()) {
            return collect();
        }

        // withoutGlobalScopes: o produto pode ter sido desativado desde a visita, mas o nome
        // ainda precisa aparecer no histórico — mesmo raciocínio de OrdemServico::withoutGlobalScopes
        // em App\Support\ProgressoDirecionamento.
        return ProdutoAuditoria::withoutGlobalScopes()->whereIn('uuid', $uuids->unique())->get()->keyBy('uuid');
    }

    /** @param  Collection<string, ProdutoAuditoria>  $produtosPorUuid */
    private static function montarSortimento(string $bruto, Collection $produtosPorUuid): array
    {
        $decodificado = json_decode($bruto, true);
        $resolver = fn (array $uuids) => collect($uuids)
            ->map(fn ($uuid) => $produtosPorUuid->get($uuid))
            ->filter()
            ->map(fn (ProdutoAuditoria $produto) => ['id' => $produto->uuid, 'descricao' => $produto->descricao])
            ->values()
            ->all();

        return [
            'presentes' => $resolver(is_array($decodificado) ? ($decodificado['presentes'] ?? []) : []),
            'ausentes' => $resolver(is_array($decodificado) ? ($decodificado['ausentes'] ?? []) : []),
        ];
    }
}
