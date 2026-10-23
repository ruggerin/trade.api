<?php

namespace App\Support;

use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Models\ProdutoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\Visita;
use App\Models\VisitaMixItem;
use App\Models\VisitaRegistro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Régua do mix da visita (docs/90 §3): no check-out, copia o mix da loja produto a produto pra
 * `visita_mix_itens`; no detalhe, cruza essa cópia com os registros não cancelados da visita.
 * A cópia nunca muda (mix alterado depois não reescreve a visita); a régua é calculada na hora,
 * porque registros chegam atrasados pela fila e podem ser cancelados.
 */
final class MixDaVisita
{
    public const REGISTRADO = 'registrado';

    public const RECORTE = 'recorte';

    public const RUPTURA = 'ruptura';

    public const SEM_REGISTRO = 'sem_registro';

    /** Ordem de "como o produto entrou no mix" quando mais de um item cobre o mesmo produto. */
    private const PRIORIDADE_ORIGEM = [
        TipoItemCampanha::PRODUTO, TipoItemCampanha::SECAO, TipoItemCampanha::DEPARTAMENTO, TipoItemCampanha::MARCA,
    ];

    /**
     * Grava a cópia do mix da loja. Idempotente: visita que já tem a cópia não grava de novo
     * (reenvio da fila, checkout forçado, correção de horário). Nunca derruba o check-out.
     */
    public static function gravar(Visita $visita): void
    {
        try {
            if (! $visita->ponto_venda_id || VisitaMixItem::where('visita_id', $visita->id)->exists()) {
                return;
            }

            // Pendente de aprovação fica de fora, como no checklist do Mix (ResolverSortimentoCampo).
            $itens = SortimentoPontoVenda::where('ponto_venda_id', $visita->ponto_venda_id)
                ->whereNull('status_aprovacao')
                ->get();
            if ($itens->isEmpty()) {
                return;
            }

            $ids = fn (TipoItemCampanha $t, string $coluna) => $itens->where('tipo_item', $t)->pluck($coluna)->filter()->unique()->values();
            $produtos = ProdutoAuditoria::withoutGlobalScopes()
                ->where('empresa_id', $visita->empresa_id)
                ->where('ativo', true)
                ->where(fn (Builder $q) => $q
                    ->whereIn('id', $ids(TipoItemCampanha::PRODUTO, 'produto_id'))
                    ->orWhereIn('secao_id', $ids(TipoItemCampanha::SECAO, 'secao_id'))
                    ->orWhereIn('departamento_id', $ids(TipoItemCampanha::DEPARTAMENTO, 'departamento_id'))
                    ->orWhereIn('marca_id', $ids(TipoItemCampanha::MARCA, 'marca_id')))
                ->with(['secao', 'departamento', 'marca'])
                ->get();

            $agora = now();
            $linhas = $produtos->map(function (ProdutoAuditoria $p) use ($itens, $visita, $agora) {
                $cobrem = $itens->filter(fn (SortimentoPontoVenda $i) => match ($i->tipo_item) {
                    TipoItemCampanha::PRODUTO => $i->produto_id === $p->id,
                    TipoItemCampanha::SECAO => $i->secao_id === $p->secao_id,
                    TipoItemCampanha::DEPARTAMENTO => $i->departamento_id === $p->departamento_id,
                    TipoItemCampanha::MARCA => $i->marca_id === $p->marca_id,
                    default => false,
                });
                $origem = collect(self::PRIORIDADE_ORIGEM)->first(fn (TipoItemCampanha $t) => $cobrem->contains('tipo_item', $t));

                return [
                    'visita_id' => $visita->id,
                    'produto_id' => $p->id,
                    'descricao' => $p->descricao,
                    'marca_id' => $p->marca_id,
                    'marca_descricao' => $p->marca?->descricao,
                    'secao_id' => $p->secao_id,
                    'secao_descricao' => $p->secao?->descricao,
                    'departamento_id' => $p->departamento_id,
                    'departamento_descricao' => $p->departamento?->descricao,
                    'propriedade' => $p->propriedade instanceof \BackedEnum ? $p->propriedade->value : $p->propriedade,
                    'produto_chave' => (bool) $p->produto_chave,
                    'origem_tipo' => ($origem ?? TipoItemCampanha::PRODUTO)->value,
                    // "Entrou na visita": todo item que cobre o produto foi criado por esse
                    // promotor entre o check-in e o check-out (docs/90 §5.1).
                    'adicionado_na_visita' => $cobrem->every(fn (SortimentoPontoVenda $i) => self::criadoNaVisita($i, $visita)),
                    'created_at' => $agora,
                ];
            });

            foreach ($linhas->chunk(500) as $lote) {
                VisitaMixItem::insertOrIgnore($lote->values()->all());
            }
        } catch (\Throwable $e) {
            Log::error('Régua do mix: falha ao gravar a cópia do mix', ['visita_id' => $visita->id, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * A régua do mix pro detalhe da visita, ou null se a visita não tem a cópia (anterior à régua
     * ou loja sem mix). Espera `registros.tipoRegistro.campos` carregados.
     *
     * @return array<string, mixed>|null
     */
    public static function regua(Visita $visita): ?array
    {
        $itens = VisitaMixItem::where('visita_id', $visita->id)->with('produto:id,uuid')->orderBy('descricao')->get();
        if ($itens->isEmpty()) {
            return null;
        }

        $registros = $visita->registros->filter(fn (VisitaRegistro $r) => ! $r->cancelado_em);
        $evidencias = self::evidencias($registros);

        $linhas = $itens->map(function (VisitaMixItem $item) use ($evidencias) {
            $porProduto = $evidencias['produto'][$item->produto_id] ?? [];
            $situacao = match (true) {
                in_array(self::RUPTURA, array_column($porProduto, 'tipo'), true) => self::RUPTURA,
                $porProduto !== [] => self::REGISTRADO,
                default => null,
            };
            $onde = array_column($porProduto, 'onde');
            if ($situacao === null) {
                $recortes = array_filter($evidencias['recorte'], fn (array $r) => self::noRecorte($item, $r));
                $situacao = $recortes !== [] ? self::RECORTE : self::SEM_REGISTRO;
                $onde = array_column($recortes, 'onde');
            }

            return [
                'produto_id' => $item->produto?->uuid,
                'descricao' => $item->descricao,
                'marca' => $item->marca_descricao,
                'secao' => $item->secao_descricao,
                'departamento' => $item->departamento_descricao,
                'produto_chave' => $item->produto_chave,
                'adicionado_na_visita' => $item->adicionado_na_visita,
                'origem_tipo' => $item->origem_tipo->value,
                'situacao' => $situacao,
                'onde' => array_values(array_unique($onde)),
            ];
        });

        $contagem = $linhas->countBy('situacao');
        $total = $linhas->count();
        $cobertos = $total - ($contagem[self::SEM_REGISTRO] ?? 0);

        return [
            'gravado_em' => $itens->first()->created_at?->toIso8601String(),
            'total' => $total,
            'cobertos' => $cobertos,
            'percentual' => (int) round($cobertos / $total * 100),
            'contagem' => [
                self::REGISTRADO => $contagem[self::REGISTRADO] ?? 0,
                self::RECORTE => $contagem[self::RECORTE] ?? 0,
                self::RUPTURA => $contagem[self::RUPTURA] ?? 0,
                self::SEM_REGISTRO => $contagem[self::SEM_REGISTRO] ?? 0,
            ],
            'itens' => $linhas->values()->all(),
        ];
    }

    /**
     * Evidência de cada registro: por produto (registro no produto, presentes/ausentes do campo
     * Mix) e por recorte (registro na seção, marca ou departamento, sem produto).
     *
     * @param  Collection<int, VisitaRegistro>  $registros
     * @return array{produto: array<int, list<array{tipo: string, onde: string}>>, recorte: list<array<string, mixed>>}
     */
    private static function evidencias(Collection $registros): array
    {
        $porProduto = [];
        $recortes = [];

        // uuid → id dos produtos citados nos campos Mix, numa query só.
        $respostasMix = [];
        foreach ($registros as $r) {
            foreach ($r->tipoRegistro?->campos ?? [] as $campo) {
                if ($campo->tipo_campo !== TipoCampoRegistro::SORTIMENTO) {
                    continue;
                }
                $valor = ($r->valores_campos ?? [])[$campo->chave] ?? null;
                $mix = is_string($valor) ? json_decode($valor, true) : (is_array($valor) ? $valor : null);
                if (is_array($mix)) {
                    $respostasMix[] = [$r, $campo->rotulo ?? $campo->chave, $mix];
                }
            }
        }
        $uuids = collect($respostasMix)->flatMap(fn ($m) => [...($m[2]['presentes'] ?? []), ...($m[2]['ausentes'] ?? [])])->unique()->values();
        $idPorUuid = $uuids->isEmpty() ? collect() : ProdutoAuditoria::withoutGlobalScopes()->whereIn('uuid', $uuids)->pluck('id', 'uuid');

        foreach ($respostasMix as [$r, $rotulo, $mix]) {
            $formulario = $r->tipoRegistro->descricao;
            foreach ($mix['presentes'] ?? [] as $uuid) {
                if ($id = $idPorUuid[$uuid] ?? null) {
                    $porProduto[$id][] = ['tipo' => self::REGISTRADO, 'onde' => "{$formulario} · {$rotulo} (presente)"];
                }
            }
            foreach ($mix['ausentes'] ?? [] as $uuid) {
                if ($id = $idPorUuid[$uuid] ?? null) {
                    $porProduto[$id][] = ['tipo' => self::RUPTURA, 'onde' => "{$formulario} · {$rotulo} (ausente)"];
                }
            }
        }

        foreach ($registros as $r) {
            $formulario = $r->tipoRegistro?->descricao ?? 'Registro';
            if ($r->produto_auditoria_id) {
                $porProduto[$r->produto_auditoria_id][] = $r->ruptura
                    ? ['tipo' => self::RUPTURA, 'onde' => "{$formulario} (ruptura)"]
                    : ['tipo' => self::REGISTRADO, 'onde' => $formulario];

                continue;
            }
            if ($r->secao_id || $r->departamento_id || $r->marca_id) {
                $recorte = collect([
                    $r->secao?->descricao ? "seção {$r->secao->descricao}" : null,
                    $r->departamento?->descricao ? "departamento {$r->departamento->descricao}" : null,
                    $r->marca?->descricao ? "marca {$r->marca->descricao}" : null,
                ])->filter()->implode(', ');
                $recortes[] = [
                    'secao_id' => $r->secao_id, 'departamento_id' => $r->departamento_id, 'marca_id' => $r->marca_id,
                    'onde' => $recorte !== '' ? "{$formulario} ({$recorte})" : $formulario,
                ];
            }
        }

        return ['produto' => $porProduto, 'recorte' => $recortes];
    }

    /** O produto está dentro de tudo o que o registro marcou (seção, departamento e/ou marca). */
    private static function noRecorte(VisitaMixItem $item, array $recorte): bool
    {
        foreach (['secao_id', 'departamento_id', 'marca_id'] as $coluna) {
            if ($recorte[$coluna] && $recorte[$coluna] !== $item->{$coluna}) {
                return false;
            }
        }

        return true;
    }

    private static function criadoNaVisita(SortimentoPontoVenda $item, Visita $visita): bool
    {
        if ($item->usuario_id !== $visita->usuario_id || ! $item->created_at || ! $visita->inicio_data) {
            return false;
        }
        $fim = $visita->fim_data ?? now();

        return $item->created_at->betweenIncluded($visita->inicio_data, $fim);
    }
}
