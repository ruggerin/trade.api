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
 * (rotas): o integrador grava por `sincronizar*`; o admin também cadastra, edita e exclui na mão
 * (docs/73 §11.2). A próxima carga do ERP sobrescreve o que vier preenchido nela.
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

    public function criarSupervisor(Request $request): JsonResponse
    {
        $supervisor = Supervisor::create($this->dados($request, new Supervisor));
        // Vendedores que chegaram antes dele, pelo `codsupervisor` do ERP.
        EquipeComercial::religarVendedores($request->user()->empresa, [$supervisor->codigo_externo]);

        return response()->json(['supervisor' => $this->supervisorJson($supervisor->load('usuario:id,uuid,nome')->loadCount('vendedores'))], 201);
    }

    public function atualizarSupervisor(Request $request, Supervisor $supervisor): JsonResponse
    {
        $codigoAnterior = $supervisor->codigo_externo;
        $supervisor->update($this->dados($request, $supervisor));

        if ($supervisor->codigo_externo !== $codigoAnterior) {
            // O código cru dos vendedores dele acompanha o novo, senão o vínculo fica apontando
            // pra um código que não existe mais.
            $supervisor->vendedores()->update(['codigo_supervisor' => $supervisor->codigo_externo]);
            EquipeComercial::religarVendedores($request->user()->empresa, [$supervisor->codigo_externo]);
        }

        return response()->json(['supervisor' => $this->supervisorJson($supervisor->load('usuario:id,uuid,nome')->loadCount('vendedores'))]);
    }

    /** Os vendedores dele ficam sem supervisor (FK `nullOnDelete`), mas guardam o código cru. */
    public function excluirSupervisor(Supervisor $supervisor): JsonResponse
    {
        $supervisor->delete();

        return response()->json(null, 204);
    }

    public function criarVendedor(Request $request): JsonResponse
    {
        $vendedor = Vendedor::create($this->dados($request, new Vendedor, $this->regrasSupervisor($request)));

        return response()->json(['vendedor' => $this->vendedorJson($this->carregarVendedor($vendedor))], 201);
    }

    public function atualizarVendedor(Request $request, Vendedor $vendedor): JsonResponse
    {
        $vendedor->update($this->dados($request, $vendedor, $this->regrasSupervisor($request)));

        return response()->json(['vendedor' => $this->vendedorJson($this->carregarVendedor($vendedor))]);
    }

    /** A carteira dele (`vendedor_ponto_venda`) sai junto, por cascata. */
    public function excluirVendedor(Vendedor $vendedor): JsonResponse
    {
        $vendedor->delete();

        return response()->json(null, 204);
    }

    private function carregarVendedor(Vendedor $vendedor): Vendedor
    {
        return $vendedor->load(['usuario:id,uuid,nome', 'supervisor:id,uuid,codigo_externo,nome'])
            ->loadCount('pontosVenda as lojas_count');
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

    /**
     * Campos do formulário, comuns a vendedor e supervisor. Na criação `$pessoa` é uma instância
     * nova (`exists = false`): código e nome passam a ser obrigatórios.
     */
    private function dados(Request $request, Model $pessoa, array $regrasExtras = []): array
    {
        $empresaId = $request->user()->empresa_id;
        $obrigatorio = $pessoa->exists ? 'sometimes' : 'required';
        $dados = $request->validate([
            'codigo_externo' => [
                $obrigatorio, 'string', 'max:64',
                Rule::unique($pessoa->getTable(), 'codigo_externo')->where('empresa_id', $empresaId)->ignore($pessoa->getKey()),
            ],
            'nome' => [$obrigatorio, 'string', 'max:255'],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
            'usuario_uuid' => [
                'sometimes', 'nullable', 'string', 'uuid',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId),
            ],
        ] + $regrasExtras, [
            'codigo_externo.unique' => 'Já existe um cadastro com este código.',
        ]);

        if (array_key_exists('usuario_uuid', $dados)) {
            $usuarioId = $dados['usuario_uuid'] ? Usuario::where('uuid', $dados['usuario_uuid'])->value('id') : null;
            $ocupado = $usuarioId && $pessoa->newQuery()->where('usuario_id', $usuarioId)
                ->when($pessoa->exists, fn ($q) => $q->whereKeyNot($pessoa->getKey()))->exists();
            abort_if($ocupado, 422, 'Este usuário já está vinculado a outro cadastro.');
            $dados['usuario_id'] = $usuarioId;
            unset($dados['usuario_uuid']);
        }

        // Supervisor escolhido na tela: grava o vínculo e o código cru, como a carga do ERP faz.
        if (array_key_exists('supervisor_uuid', $dados)) {
            $supervisor = $dados['supervisor_uuid'] ? Supervisor::where('uuid', $dados['supervisor_uuid'])->first() : null;
            $dados['supervisor_id'] = $supervisor?->id;
            $dados['codigo_supervisor'] = $supervisor?->codigo_externo;
            unset($dados['supervisor_uuid']);
        }

        return $dados;
    }

    private function regrasSupervisor(Request $request): array
    {
        return ['supervisor_uuid' => [
            'sometimes', 'nullable', 'string', 'uuid',
            Rule::exists('supervisores', 'uuid')->where('empresa_id', $request->user()->empresa_id),
        ]];
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
