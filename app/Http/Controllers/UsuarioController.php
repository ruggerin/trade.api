<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Requests\Usuario\AtualizarFotoUsuarioRequest;
use App\Http\Requests\Usuario\StoreUsuarioRequest;
use App\Http\Requests\Usuario\UpdateUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\CentroCusto;
use App\Models\Supervisor;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use App\Support\Fuso;
use Carbon\Carbon;
use App\Support\Adesao;
use App\Support\Seguranca;
use App\Support\UsoLicencas;
use App\Support\VinculoAparelho;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsuarioController extends Controller
{
    /**
     * Serve a foto de perfil enviada pelo próprio usuário (ver AuthController::atualizarFoto) —
     * fora do grupo `permissao:usuarios.gerenciar` de propósito: qualquer autenticado da mesma
     * empresa pode ver a foto de um colega (isolamento de tenant já garantido pelo route model
     * binding + global scope), não só quem gerencia usuários. Mesmo padrão de
     * VisitaRegistroController::imagem.
     */
    public function foto(Usuario $usuario): StreamedResponse
    {
        abort_if(! $usuario->foto_path, 404);

        return Storage::disk(config('filesystems.default'))->response($usuario->foto_path);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'data_campo' => ['nullable', 'in:created_at,updated_at'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date'],
            'ordenar' => ['nullable', 'in:nome,recentes'],
        ]);
        $campoData = $request->input('data_campo', 'created_at');

        $usuarios = Usuario::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('user_type'), fn ($query) => $query->where('user_type', $request->string('user_type')))
            // Só tem efeito prático pro SUPERADMIN (ver EnsurePermissao) — o BelongsToEmpresa já
            // restringe ADMIN/GESTOR à própria empresa, então filtrar por outra aqui só resulta
            // em lista vazia (inofensivo, nunca vaza dado de outro tenant).
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // Nome, e-mail ou código externo na mesma caixa (mesmo padrão da busca de produto).
            ->when($request->filled('busca'), function ($query) use ($request) {
                $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                $query->where(fn ($q) => $q
                    ->where('nome', 'ilike', $termo)
                    ->orWhere('email', 'ilike', $termo)
                    ->orWhere('codigo_externo', 'ilike', $termo));
            })
            // `sem_perfil` = GESTOR/PROMOTOR ainda sem perfil atribuído (pendência de configuração).
            ->when($request->filled('perfil_uuid'), fn ($query) => $request->string('perfil_uuid')->toString() === 'sem_perfil'
                ? $query->whereNull('perfil_id')->whereIn('user_type', [UserType::GESTOR, UserType::PROMOTOR])
                : $query->whereHas('perfil', fn ($p) => $p->where('uuid', $request->string('perfil_uuid'))))
            // Período por data de cadastro (quem entrou na base) ou de última alteração do cadastro.
            // Período por data local no fuso da empresa (docs/50 §4.3), não meia-noite UTC.
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, $campoData, $request->input('data_inicio'), $request->input('data_fim'), Fuso::daEmpresa($request->user()->empresa)))
            ->with(['perfil', 'centroCusto', 'supervisor', 'dispositivo', 'empresa'])
            ->when(
                $request->input('ordenar') === 'recentes',
                fn ($query) => $query->orderByDesc($campoData)->orderBy('nome'),
                fn ($query) => $query->orderBy('nome'),
            )
            // `por_pagina` é opt-in (ninguém manda por padrão) — usado pelo Planejador de
            // Visitas pra listar todos os promotores de uma vez no seletor, sem paginação real.
            ->paginate($request->filled('por_pagina') ? min($request->integer('por_pagina'), 200) : null);

        // Último acesso / frequência / "sumiu" de cada um (docs/52 §4.1) — uma consulta pra página toda.
        Adesao::anexar($usuarios->items());

        return response()->json([
            'usuarios' => UsuarioResource::collection($usuarios->items()),
            'meta' => [
                'current_page' => $usuarios->currentPage(),
                'last_page' => $usuarios->lastPage(),
                'per_page' => $usuarios->perPage(),
                'total' => $usuarios->total(),
            ],
        ]);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        $usuario->load(['perfil', 'centroCusto', 'supervisor', 'dispositivo', 'empresa']);
        Adesao::anexar([$usuario]);

        return response()->json([
            'usuario' => new UsuarioResource($usuario),
        ]);
    }

    public function store(StoreUsuarioRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // SUPERADMIN escolhe a empresa no formulário (empresa_uuid, ver StoreUsuarioRequest) —
        // não tem empresa própria pra herdar. ADMIN/GESTOR criam sempre na própria empresa.
        $empresa = $request->user()->user_type === UserType::SUPERADMIN
            ? Empresa::where('uuid', $dados['empresa_uuid'])->firstOrFail()
            : $request->user()->empresa;

        // Regra de negócio 4 (docs/02-API-BACKEND.md): limite de usuários do plano. Contagem
        // explícita por empresa_id (não Usuario::count() ambíguo) — pro SUPERADMIN o global
        // scope de tenant não filtra nada, então Usuario::count() contaria todo mundo, de
        // todas as empresas.
        $totalUsuarios = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();
        if ($empresa->limite_usuarios !== null && $totalUsuarios >= $empresa->limite_usuarios) {
            return response()->json([
                'message' => "Limite de usuários do plano {$empresa->plano->value} atingido ({$empresa->limite_usuarios}). Faça upgrade para adicionar mais.",
            ], 422);
        }

        // Licença do app mobile atribuída no cadastro (docs/67 §3), qualquer user_type — PROMOTOR
        // nasce com ela (só trabalha no app). Limite separado de limite_usuarios (headcount geral).
        $licencaMobile = $dados['licenca_mobile'] ?? $dados['user_type'] === UserType::PROMOTOR->value;
        if ($licencaMobile && ! UsoLicencas::comportaMaisUma($empresa)) {
            return response()->json(['message' => UsoLicencas::mensagemLimite($empresa)], 422);
        }

        $usuario = Usuario::create([
            'empresa_id' => $empresa->id,
            'nome' => $dados['nome'],
            'email' => $dados['email'],
            'senha_hash' => Hash::make($dados['senha']),
            'user_type' => $dados['user_type'],
            'perfil_id' => isset($dados['perfil_uuid'])
                ? Perfil::where('uuid', $dados['perfil_uuid'])->value('id')
                : null,
            'centro_custo_id' => isset($dados['centro_custo_uuid'])
                ? CentroCusto::where('uuid', $dados['centro_custo_uuid'])->value('id')
                : null,
            'avatar_url' => $dados['avatar_url'] ?? null,
            'licenca_mobile' => $licencaMobile,
            'supervisor_id' => isset($dados['supervisor_uuid'])
                ? Supervisor::where('uuid', $dados['supervisor_uuid'])->value('id')
                : null,
        ]);
        $usuario->load(['perfil', 'centroCusto', 'supervisor', 'dispositivo', 'empresa']);

        return response()->json([
            'usuario' => new UsuarioResource($usuario),
        ], 201);
    }

    public function update(UpdateUsuarioRequest $request, Usuario $usuario): JsonResponse
    {
        $dados = $request->validated();

        if (isset($dados['senha'])) {
            $dados['senha_hash'] = Hash::make($dados['senha']);
            unset($dados['senha']);
        }

        if (array_key_exists('perfil_uuid', $dados)) {
            $dados['perfil_id'] = $dados['perfil_uuid']
                ? Perfil::where('uuid', $dados['perfil_uuid'])->value('id')
                : null;
            unset($dados['perfil_uuid']);
        }

        if (array_key_exists('centro_custo_uuid', $dados)) {
            $dados['centro_custo_id'] = $dados['centro_custo_uuid']
                ? CentroCusto::where('uuid', $dados['centro_custo_uuid'])->value('id')
                : null;
            unset($dados['centro_custo_uuid']);
        }

        if (array_key_exists('supervisor_uuid', $dados)) {
            $dados['supervisor_id'] = $dados['supervisor_uuid']
                ? Supervisor::where('uuid', $dados['supervisor_uuid'])->value('id')
                : null;
            unset($dados['supervisor_uuid']);
        }

        // Licença do app (docs/67 §3): atribuir, reativar quem tem ou transformar alguém em PROMOTOR
        // (que ganha a licença, só trabalha no app) passa a consumir — mesma trava do store (brecha
        // B2). Só checa quando o usuário NÃO consumia antes, senão editar o nome de quem já tem
        // licença com o limite cheio daria 422.
        $viraPromotor = ($dados['user_type'] ?? null) === UserType::PROMOTOR->value && $usuario->user_type !== UserType::PROMOTOR;
        $licencaDepois = (bool) ($dados['licenca_mobile'] ?? ($viraPromotor ? true : $usuario->licenca_mobile));
        $consumiaAntes = $usuario->ativo && $usuario->licenca_mobile;
        $consomeDepois = ($dados['ativo'] ?? $usuario->ativo) && $licencaDepois;
        if (! $consumiaAntes && $consomeDepois && ! UsoLicencas::comportaMaisUma($usuario->empresa)) {
            return response()->json(['message' => UsoLicencas::mensagemLimite($usuario->empresa)], 422);
        }
        $dados['licenca_mobile'] = $licencaDepois;
        $perdeuLicenca = $usuario->licenca_mobile && ! $licencaDepois;

        $usuario->update($dados);

        // Licença removida: o aparelho sai (vai pro histórico) e a sessão do app cai com o motivo.
        if ($perdeuLicenca && ($aparelho = $usuario->dispositivo()->first())) {
            VinculoAparelho::desvincular($aparelho, 'LICENCA_REMOVIDA', $request->user());
            Seguranca::registrar(Seguranca::APARELHO_DESVINCULADO, $usuario, $aparelho, [
                'motivo' => 'licença removida',
                'por' => $request->user()->nome,
            ]);
        }
        $usuario->load(['perfil', 'centroCusto', 'supervisor', 'dispositivo']);

        return response()->json([
            'usuario' => new UsuarioResource($usuario),
        ]);
    }

    public function destroy(Request $request, Usuario $usuario): JsonResponse
    {
        if ($usuario->is($request->user())) {
            return response()->json([
                'message' => 'Não é possível desativar o seu próprio usuário.',
            ], 422);
        }

        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        // Revoga os tokens já emitidos: sem isso, uma sessão aberta continuaria funcionando
        // normalmente mesmo com o usuário desativado (login novo já é bloqueado, ver
        // AuthController::login, mas isso não afeta quem já está logado).
        $usuario->update(['ativo' => false]);
        $usuario->tokens()->delete();

        return response()->json(status: 204);
    }

    /**
     * ADMIN/GESTOR envia (ou substitui) a foto de outro usuário da empresa — mesma lógica de
     * storage do self-service (AuthController::atualizarFoto), só que gated por
     * `usuarios.gerenciar` em vez de ser sempre a foto de quem está autenticado.
     */
    public function atualizarFoto(AtualizarFotoUsuarioRequest $request, Usuario $usuario): JsonResponse
    {
        if ($usuario->foto_path) {
            Storage::disk(config('filesystems.default'))->delete($usuario->foto_path);
        }

        $caminho = $request->file('imagem')->store("usuarios/{$usuario->id}", config('filesystems.default'));
        $usuario->update(['foto_path' => $caminho]);

        return response()->json([
            'usuario' => new UsuarioResource($usuario->fresh()->loadMissing(['empresa', 'perfil', 'centroCusto', 'supervisor', 'dispositivo'])),
        ]);
    }

    public function removerFoto(Usuario $usuario): JsonResponse
    {
        if ($usuario->foto_path) {
            Storage::disk(config('filesystems.default'))->delete($usuario->foto_path);
            $usuario->update(['foto_path' => null]);
        }

        return response()->json([
            'usuario' => new UsuarioResource($usuario->fresh()->loadMissing(['empresa', 'perfil', 'centroCusto', 'supervisor', 'dispositivo'])),
        ]);
    }

    /**
     * Desvincula o dispositivo atual e revoga a sessão dele — uso típico: aparelho
     * perdido/roubado, ou troca de celular do promotor sem esperar ele logar de novo pra
     * liberar a licença. Não é a mesma coisa que a trava automática do login (ver
     * AuthController::login): aqui é uma ação deliberada do ADMIN/GESTOR, sem precisar do
     * promotor fazer nada primeiro. O próximo aparelho que logar é vinculado sem contar como troca
     * self-service (docs/67 §4.3). O vínculo vai pro histórico; a sessão do app cai com o motivo,
     * a do admin web (se houver) fica.
     */
    public function revogarDispositivo(Request $request, Usuario $usuario): JsonResponse
    {
        if (! $usuario->dispositivo) {
            return response()->json([
                'message' => 'Este usuário não tem nenhum dispositivo vinculado.',
            ], 422);
        }

        $dispositivo = $usuario->dispositivo;
        VinculoAparelho::desvincular($dispositivo, 'DESVINCULADO_ADMIN', $request->user());
        Seguranca::registrar(Seguranca::APARELHO_DESVINCULADO, $usuario, $dispositivo, [
            'por' => $request->user()->nome,
        ]);

        return response()->json(status: 204);
    }

    /**
     * Linha do tempo de eventos do usuário (login, entrada/saída de PDV com localização,
     * registros de visita) — pensado sobretudo pra PROMOTOR, mas funciona pra qualquer
     * user_type (ADMIN/GESTOR não geram visita/registro, só login mesmo).
     *
     * Não existe uma tabela única de "eventos": isso é montado juntando três fontes
     * (usuario_login_logs, visitas, visita_registros), cada uma limitada às últimas
     * `$limitePorFonte` linhas antes de juntar — evita carregar o histórico inteiro de um
     * usuário muito antigo na memória só pra paginar. Suficiente pro "o que esse promotor andou
     * fazendo ultimamente" a que essa tela se propõe; não é um relatório histórico completo.
     *
     * Filtros opcionais: `?tipos=LOGIN,REGISTRO` (lista separada por vírgula, restringe quais
     * tipos de evento voltam — usado pela aba "Localização" do admin web, que pede só
     * `VISITA_INICIO,VISITA_FIM`) e `?data_inicio=&data_fim=` (formato `YYYY-MM-DD`, inclusive
     * dos dois lados). As datas filtram cada fonte já na consulta (não só depois de montar os
     * eventos) — sem isso, um usuário com muita atividade recente poderia nunca alcançar um
     * período mais antigo dentro do limite de 100 linhas por fonte.
     */
    public function historico(Request $request, Usuario $usuario): JsonResponse
    {
        $limitePorFonte = 100;

        $tiposFiltro = $request->filled('tipos') ? explode(',', $request->string('tipos')) : null;
        // Datas locais no fuso da empresa, em UTC pra comparar com o banco (docs/50 §4.3).
        $fuso = Fuso::daEmpresa($usuario->empresa);
        $dataInicio = $request->filled('data_inicio') ? Fuso::intervaloDoDia($request->string('data_inicio'), $fuso)[0] : null;
        $dataFim = $request->filled('data_fim') ? Fuso::intervaloDoDia($request->string('data_fim'), $fuso)[1] : null;

        $eventos = collect();

        if (! $tiposFiltro || in_array('LOGIN', $tiposFiltro, true)) {
            $usuario->loginLogs()
                ->when($dataInicio, fn ($q) => $q->where('created_at', '>=', $dataInicio))
                ->when($dataFim, fn ($q) => $q->where('created_at', '<=', $dataFim))
                ->latest()
                ->limit($limitePorFonte)
                ->get()
                ->each(function ($log) use ($eventos): void {
                    $eventos->push([
                        'tipo' => 'LOGIN',
                        'ocorrido_em' => $log->created_at,
                        'ponto_venda' => null,
                        'produto' => null,
                        'tipo_registro' => null,
                        'ruptura' => null,
                        'observacao' => null,
                        'dispositivo' => $log->dispositivo_nome ?? $log->dispositivo_identificador,
                        'latitude' => null,
                        'longitude' => null,
                        'distancia_metros' => null,
                    ]);
                });
        }

        $precisaVisitas = ! $tiposFiltro || array_intersect(['VISITA_INICIO', 'VISITA_FIM', 'REGISTRO'], $tiposFiltro);
        $visitas = collect();

        if ($precisaVisitas) {
            // Janela alargada (início OU fim dentro do período) — uma visita que começou antes
            // do período mas terminou dentro dele ainda deve aparecer com o evento de saída.
            $visitas = $usuario->visitas()
                ->with('pontoVenda')
                ->when($dataInicio || $dataFim, function ($q) use ($dataInicio, $dataFim): void {
                    $q->where(function ($q) use ($dataInicio, $dataFim): void {
                        $q->when($dataInicio, fn ($q) => $q->where('inicio_data', '>=', $dataInicio))
                            ->when($dataFim, fn ($q) => $q->where('inicio_data', '<=', $dataFim));
                    })->orWhere(function ($q) use ($dataInicio, $dataFim): void {
                        $q->when($dataInicio, fn ($q) => $q->where('fim_data', '>=', $dataInicio))
                            ->when($dataFim, fn ($q) => $q->where('fim_data', '<=', $dataFim));
                    });
                })
                ->latest('inicio_data')
                ->limit($limitePorFonte)
                ->get();

            $visitas->each(function ($visita) use ($eventos, $tiposFiltro): void {
                $pontoVenda = $visita->pontoVenda
                    ? ['id' => $visita->pontoVenda->uuid, 'fantasia' => $visita->pontoVenda->fantasia]
                    : null;

                if (! $tiposFiltro || in_array('VISITA_INICIO', $tiposFiltro, true)) {
                    $eventos->push([
                        'tipo' => 'VISITA_INICIO',
                        'ocorrido_em' => $visita->inicio_data,
                        'ponto_venda' => $pontoVenda,
                        'produto' => null,
                        'tipo_registro' => null,
                        'ruptura' => null,
                        'observacao' => null,
                        'dispositivo' => null,
                        'latitude' => $visita->inicio_latitude,
                        'longitude' => $visita->inicio_longitude,
                        'distancia_metros' => $visita->inicio_distancia_metros,
                    ]);
                }

                if ($visita->fim_data && (! $tiposFiltro || in_array('VISITA_FIM', $tiposFiltro, true))) {
                    $eventos->push([
                        'tipo' => 'VISITA_FIM',
                        'ocorrido_em' => $visita->fim_data,
                        'ponto_venda' => $pontoVenda,
                        'produto' => null,
                        'tipo_registro' => null,
                        'ruptura' => null,
                        'observacao' => null,
                        'dispositivo' => null,
                        'latitude' => $visita->fim_latitude,
                        'longitude' => $visita->fim_longitude,
                        'distancia_metros' => $visita->fim_distancia_metros,
                    ]);
                }
            });
        }

        if (! $tiposFiltro || in_array('REGISTRO', $tiposFiltro, true)) {
            VisitaRegistro::whereIn('visita_id', $usuario->visitas()->pluck('id'))
                ->with(['produtoAuditoria', 'tipoRegistro', 'visita.pontoVenda'])
                ->when($dataInicio, fn ($q) => $q->where('created_at', '>=', $dataInicio))
                ->when($dataFim, fn ($q) => $q->where('created_at', '<=', $dataFim))
                ->latest()
                ->limit($limitePorFonte)
                ->get()
                ->each(function (VisitaRegistro $registro) use ($eventos): void {
                    $pontoVenda = $registro->visita?->pontoVenda
                        ? ['id' => $registro->visita->pontoVenda->uuid, 'fantasia' => $registro->visita->pontoVenda->fantasia]
                        : null;

                    $eventos->push([
                        'tipo' => 'REGISTRO',
                        'ocorrido_em' => $registro->created_at,
                        'ponto_venda' => $pontoVenda,
                        'produto' => $registro->produtoAuditoria
                            ? ['id' => $registro->produtoAuditoria->uuid, 'descricao' => $registro->produtoAuditoria->descricao]
                            : null,
                        'tipo_registro' => $registro->tipoRegistro?->descricao,
                        'ruptura' => $registro->ruptura,
                        'observacao' => $registro->observacao,
                        'dispositivo' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'distancia_metros' => null,
                    ]);
                });
        }

        // Segunda passada de data exata (não só a janela alargada usada na consulta de
        // visitas acima) — garante que um VISITA_INICIO fora do período não vaze só porque a
        // visita como um todo bateu na janela por causa do fim_data.
        if ($dataInicio) {
            $eventos = $eventos->filter(fn ($e) => $e['ocorrido_em']->gte($dataInicio));
        }
        if ($dataFim) {
            $eventos = $eventos->filter(fn ($e) => $e['ocorrido_em']->lte($dataFim));
        }

        $eventos = $eventos->sortByDesc('ocorrido_em')->values();

        $perPage = 20;
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $eventos->forPage($page, $perPage)->values(),
            $eventos->count(),
            $perPage,
            $page,
        );

        return response()->json([
            'eventos' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
