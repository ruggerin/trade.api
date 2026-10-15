<?php

namespace App\Http\Controllers;

use App\Models\PontoVenda;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Vendedor;
use App\Support\CarteiraVendedores;
use App\Support\EquipeComercial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vendedores e supervisores espelhados do ERP (docs/73 Fase 1). Tudo com `vendedores.gerenciar`
 * (rotas): o integrador grava por `sincronizar*`, o admin confere e ajusta só contato e o vínculo
 * com o usuário do Horus — código, nome e hierarquia são do ERP.
 */
class EquipeComercialController extends Controller
{
    public function supervisores(Request $request): JsonResponse
    {
        $supervisores = $this->filtrar(Supervisor::query(), $request)
            ->with('usuario:id,uuid,nome')
            ->withCount(['vendedores' => fn ($q) => $q->where('ativo', true)])
            ->orderBy('nome')
            ->paginate($this->porPagina($request));

        return response()->json([
            'supervisores' => $supervisores->getCollection()->map(fn (Supervisor $s) => $this->supervisorJson($s))->all(),
            'meta' => $this->meta($supervisores),
        ]);
    }

    public function vendedores(Request $request): JsonResponse
    {
        $request->validate(['supervisor_uuid' => ['nullable', 'string', 'uuid']]);

        $vendedores = $this->filtrar(Vendedor::query(), $request)
            ->when($request->filled('supervisor_uuid'), fn ($q) => $q->whereHas(
                'supervisor', fn ($s) => $s->where('uuid', $request->string('supervisor_uuid')->toString()),
            ))
            // Vendedor cujo `codsupervisor` não casou com ninguém — o que a tela mais precisa mostrar.
            ->when($request->boolean('sem_supervisor'), fn ($q) => $q->whereNull('supervisor_id'))
            ->with(['usuario:id,uuid,nome', 'supervisor:id,uuid,codigo_externo,nome'])
            ->withCount('pontosVenda as lojas_count')
            ->orderBy('nome')
            ->paginate($this->porPagina($request));

        return response()->json([
            'vendedores' => $vendedores->getCollection()->map(fn (Vendedor $v) => $this->vendedorJson($v))->all(),
            'meta' => $this->meta($vendedores),
        ]);
    }

    public function atualizarSupervisor(Request $request, Supervisor $supervisor): JsonResponse
    {
        $supervisor->update($this->contato($request, $supervisor, 'supervisores'));

        return response()->json(['supervisor' => $this->supervisorJson($supervisor->load('usuario:id,uuid,nome')->loadCount('vendedores'))]);
    }

    public function atualizarVendedor(Request $request, Vendedor $vendedor): JsonResponse
    {
        $vendedor->update($this->contato($request, $vendedor, 'vendedores'));

        return response()->json(['vendedor' => $this->vendedorJson($vendedor->load(['usuario:id,uuid,nome', 'supervisor:id,uuid,codigo_externo,nome']))]);
    }

    /** Integrador do ERP: lote JSON, idempotente por `codigo_externo`. Grava o que é válido. */
    public function sincronizarSupervisores(Request $request): JsonResponse
    {
        $linhas = $this->lote($request, 'supervisores');

        return response()->json(EquipeComercial::supervisores($request->user()->empresa, $linhas, tudoOuNada: false));
    }

    public function sincronizarVendedores(Request $request): JsonResponse
    {
        $linhas = $this->lote($request, 'vendedores');

        return response()->json(EquipeComercial::vendedores($request->user()->empresa, $linhas, tudoOuNada: false));
    }

    /**
     * Carteira vendedor × loja pelo integrador (docs/73 §11.1): `{vinculos: [{codigo_externo_loja,
     * codigo_externo_vendedor}]}`. Cada loja do lote fica exatamente com os vendedores listados.
     */
    public function sincronizarCarteira(Request $request): JsonResponse
    {
        $request->validate([
            'vinculos' => ['required', 'array', 'min:1', 'max:'.CarteiraVendedores::MAX_LINHAS],
            'vinculos.*' => ['array'],
        ]);
        $linhas = collect($request->input('vinculos'))->values()
            ->map(fn (array $item, int $i) => ['numero' => $i + 1, 'dados' => $item])
            ->all();

        return response()->json(CarteiraVendedores::executar($request->user()->empresa, $linhas, tudoOuNada: false));
    }

    /** Ajuste 1 a 1 pela tela da loja — idempotente. */
    public function vincularLoja(Request $request, PontoVenda $pontoVenda, Vendedor $vendedor): JsonResponse
    {
        $pontoVenda->vendedores()->syncWithoutDetaching([$vendedor->id]);

        return $this->carteiraDaLoja($request, $pontoVenda);
    }

    public function desvincularLoja(Request $request, PontoVenda $pontoVenda, Vendedor $vendedor): JsonResponse
    {
        $pontoVenda->vendedores()->detach($vendedor->id);

        return $this->carteiraDaLoja($request, $pontoVenda);
    }

    private function carteiraDaLoja(Request $request, PontoVenda $pontoVenda): JsonResponse
    {
        return response()->json([
            'vendedores' => EquipeComercial::vendedoresDaLoja($pontoVenda->vendedores()->with('supervisor:id,uuid,nome')->get(), $request->user()),
        ]);
    }

    /** @return list<array{numero: int, dados: array<string, mixed>}> */
    private function lote(Request $request, string $chave): array
    {
        $request->validate([
            $chave => ['required', 'array', 'min:1', 'max:'.EquipeComercial::MAX_LINHAS],
            "{$chave}.*" => ['array'],
        ]);

        // `numero` = posição no array (1-based), pra resposta apontar a linha recusada.
        return collect($request->input($chave))->values()
            ->map(fn (array $item, int $i) => ['numero' => $i + 1, 'dados' => $item])
            ->all();
    }

    /** Só contato e vínculo com usuário: o resto vem do ERP. */
    private function contato(Request $request, Model $pessoa, string $tabela): array
    {
        $dados = $request->validate([
            'telefone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'usuario_uuid' => [
                'sometimes', 'nullable', 'string', 'uuid',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $request->user()->empresa_id),
            ],
        ]);

        if (array_key_exists('usuario_uuid', $dados)) {
            $usuarioId = $dados['usuario_uuid'] ? Usuario::where('uuid', $dados['usuario_uuid'])->value('id') : null;
            $ocupado = $usuarioId && $pessoa->newQuery()->where('usuario_id', $usuarioId)->whereKeyNot($pessoa->getKey())->exists();
            abort_if($ocupado, 422, 'Este usuário já está vinculado a outro cadastro.');
            $dados['usuario_id'] = $usuarioId;
            unset($dados['usuario_uuid']);
        }

        return $dados;
    }

    private function filtrar(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->has('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            // Código ou nome na mesma caixa.
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.addcslashes($request->string('busca')->toString(), '%_\\').'%';
                $q->where(fn ($w) => $w->where('nome', 'ilike', $termo)->orWhere('codigo_externo', 'ilike', $termo));
            });
    }

    private function porPagina(Request $request): int
    {
        return $request->filled('por_pagina') ? max(1, min($request->integer('por_pagina'), 200)) : 50;
    }

    private function meta($paginado): array
    {
        return [
            'current_page' => $paginado->currentPage(),
            'last_page' => $paginado->lastPage(),
            'per_page' => $paginado->perPage(),
            'total' => $paginado->total(),
        ];
    }

    private function supervisorJson(Supervisor $s): array
    {
        return [
            'id' => $s->uuid,
            'codigo_externo' => $s->codigo_externo,
            'nome' => $s->nome,
            'telefone' => $s->telefone,
            'email' => $s->email,
            'ativo' => $s->ativo,
            'usuario' => $s->usuario ? ['id' => $s->usuario->uuid, 'nome' => $s->usuario->nome] : null,
            'vendedores_count' => $s->vendedores_count ?? 0,
            'updated_at' => $s->updated_at,
        ];
    }

    private function vendedorJson(Vendedor $v): array
    {
        return [
            'id' => $v->uuid,
            'codigo_externo' => $v->codigo_externo,
            'nome' => $v->nome,
            'telefone' => $v->telefone,
            'email' => $v->email,
            'ativo' => $v->ativo,
            'codigo_supervisor' => $v->codigo_supervisor,
            'supervisor' => $v->supervisor ? ['id' => $v->supervisor->uuid, 'codigo_externo' => $v->supervisor->codigo_externo, 'nome' => $v->supervisor->nome] : null,
            'usuario' => $v->usuario ? ['id' => $v->usuario->uuid, 'nome' => $v->usuario->nome] : null,
            'lojas_count' => $v->lojas_count ?? null,
            'updated_at' => $v->updated_at,
        ];
    }
}
