<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Enums\PlanoEmpresa;
use App\Http\Requests\Auth\SignupEmpresaRequest;
use App\Http\Requests\Empresa\UpdateEmpresaRequest;
use App\Http\Requests\EmpresaSuperadmin\StoreEmpresaSuperadminRequest;
use App\Http\Requests\EmpresaSuperadmin\UpdateEmpresaSuperadminRequest;
use App\Http\Resources\EmpresaResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Dispositivo;
use App\Models\ClassePontoVenda;
use App\Models\Empresa;
use App\Models\SolicitacaoAparelho;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\Adesao;
use App\Support\DocumentosLegais;
use App\Support\Fuso;
use App\Support\ParametrosPadrao;
use App\Support\RelatoriosPadrao;
use App\Support\SessaoApp;
use App\Support\UsoLicencas;
use App\Support\VinculoAparelho;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmpresaController extends Controller
{
    /**
     * Cadastro self-serve: cria a empresa (plano Gratuito) + o primeiro usuário (ADMIN), e já
     * retorna um token (login automático) — ver docs/02-API-BACKEND.md#empresas-tenant.
     */
    public function signup(SignupEmpresaRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // Confere antes de criar qualquer coisa: versão desatualizada = 409, nada gravado.
        $documentosAceitos = [];
        if (! empty($dados['documentos'])) {
            $documentosAceitos = DocumentosLegais::vigentesConferidos($dados['documentos']);
            if ($documentosAceitos === null) {
                return response()->json([
                    'message' => 'Os documentos foram atualizados. Leia a versão atual antes de aceitar.',
                ], 409);
            }
        }

        [$empresa, $usuario] = DB::transaction(function () use ($dados) {
            $empresa = Empresa::create([
                'razao_social' => $dados['razao_social'],
                'nome_fantasia' => $dados['nome_fantasia'],
                'cnpj' => $dados['cnpj'],
                'plano' => PlanoEmpresa::GRATUITO,
                'limite_usuarios' => 3,
                'limite_pontos_venda' => 3,
            ]);

            // Sem usuário autenticado neste request (rota pública), então BelongsToEmpresa não
            // preenche empresa_id sozinho — setamos explicitamente aqui.
            $usuario = Usuario::create([
                'empresa_id' => $empresa->id,
                'nome' => $dados['admin_nome'],
                'email' => $dados['admin_email'],
                'senha_hash' => Hash::make($dados['admin_senha']),
                'user_type' => UserType::ADMIN,
            ]);

            TipoRegistro::seedPadrao($empresa->id);
            // O signup público não completava os parâmetros (docs/60 §6.2) — agora igual ao
            // storeSuperadmin, mais os relatórios padrão do gerador.
            ParametrosPadrao::completar($empresa);
            RelatoriosPadrao::completar($empresa);

            return [$empresa, $usuario];
        });

        DocumentosLegais::registrarAceite($usuario, $documentosAceitos, $request);
        $usuario->documentosPendentes = DocumentosLegais::pendentes($usuario);

        $token = $usuario->createToken('acesso-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => new UsuarioResource($usuario),
            'empresa' => new EmpresaResource($empresa),
        ], 201);
    }

    /**
     * Dados da própria empresa do usuário autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        $empresa = $request->user()->empresa;

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
        ]);
    }

    /**
     * Auto-edição da própria empresa pelo ADMIN (razão social/nome fantasia) — trocar de plano
     * ou limites não é feito por aqui (sem billing nesta fase), só o SUPERADMIN faz isso via
     * updateSuperadmin. Ver docs/02-API-BACKEND.md.
     */
    public function update(UpdateEmpresaRequest $request): JsonResponse
    {
        $empresa = $request->user()->empresa;
        $empresa->update($request->validated());

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
        ]);
    }

    /**
     * Licenças e plano do próprio tenant pro ADMIN (docs/67 §3) — os mesmos números da tela do
     * SUPERADMIN (UsoLicencas), quem tem licença do app (com o aparelho, se já entrou), quem pode
     * receber uma e os pedidos de liberação. Só a empresa de quem pede: o scope de tenant já
     * filtra Usuario.
     */
    public function licencas(Request $request): JsonResponse
    {
        $empresa = $request->user()->empresa;

        $usuarios = Usuario::query()
            ->where('ativo', true)
            ->where('licenca_mobile', true)
            ->with('dispositivo')
            ->orderBy('nome')
            ->get();
        $ids = $usuarios->pluck('id');

        // Último uso do app no aparelho atual (login ou qualquer token dele — SessaoApp).
        $ultimoUso = SessaoApp::ultimoUsoPorAparelho($usuarios->pluck('dispositivo'));

        // Trocas de aparelho em 30 dias = vínculos que não foram o primeiro (histórico, docs/67 §4.2).
        $trocas30d = Dispositivo::query()
            ->whereIn('usuario_id', $ids)
            ->where('origem', '!=', Dispositivo::ORIGEM_PRIMEIRO)
            ->where('vinculado_em', '>=', now()->subDays(30))
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id, count(*) as trocas')
            ->pluck('trocas', 'usuario_id');

        $solicitacoes = SolicitacaoAparelho::query()
            ->where('status', SolicitacaoAparelho::PENDENTE)
            ->with(['usuario:id,uuid,nome,user_type', 'usuario.dispositivo:id,usuario_id,nome,desvinculado_em'])
            ->latest('updated_at')
            ->get();

        return response()->json([
            'plano' => $empresa->plano,
            'uso' => UsoLicencas::resumo($empresa),
            'troca_aparelho' => [
                'dias_entre_trocas' => VinculoAparelho::diasEntreTrocas($empresa),
            ],
            'usando_agora' => $usuarios->filter(fn (Usuario $u) => $u->dispositivo && SessaoApp::online($ultimoUso[$u->dispositivo->id] ?? null))->count(),
            'com_aparelho' => $usuarios->filter(fn (Usuario $u) => $u->dispositivo !== null)->count(),
            'licenciados' => $usuarios->map(function (Usuario $usuario) use ($ultimoUso, $trocas30d): array {
                $aparelho = $usuario->dispositivo;
                $ultimo = $aparelho ? ($ultimoUso[$aparelho->id] ?? null) : null;
                $trocas = (int) ($trocas30d[$usuario->id] ?? 0);

                return [
                    'usuario_uuid' => $usuario->uuid,
                    'usuario_nome' => $usuario->nome,
                    'user_type' => $usuario->user_type,
                    // null = licença atribuída, mas ainda não entrou no app.
                    'aparelho' => $aparelho?->nome,
                    'tem_aparelho' => $aparelho !== null,
                    'plataforma' => $aparelho?->plataforma,
                    'versao_so' => $aparelho?->versao_so,
                    'versao_app' => $aparelho?->versao_app,
                    'integridade_status' => $aparelho?->integridade_status,
                    'vinculado_em' => $aparelho?->vinculado_em,
                    'ultimo_uso_em' => $ultimo,
                    'online' => SessaoApp::online($ultimo),
                    'trocas_aparelho_30d' => $trocas,
                    // Painel do ADMIN destaca quem troca demais (docs/67 §4.5).
                    'alerta_trocas' => $trocas > 2,
                ];
            })->values(),
            // Quem pode receber licença ("Atribuir licença").
            'sem_licenca' => Usuario::query()
                ->where('ativo', true)
                ->where('licenca_mobile', false)
                ->orderBy('nome')
                ->get(['uuid', 'nome', 'user_type'])
                ->map(fn (Usuario $u): array => ['usuario_uuid' => $u->uuid, 'usuario_nome' => $u->nome, 'user_type' => $u->user_type])
                ->values(),
            'solicitacoes' => $solicitacoes->map(fn (SolicitacaoAparelho $s): array => [
                'uuid' => $s->uuid,
                'usuario_uuid' => $s->usuario?->uuid,
                'usuario_nome' => $s->usuario?->nome,
                'user_type' => $s->usuario?->user_type,
                'aparelho_atual' => $s->usuario?->dispositivo?->nome,
                'aparelho' => $s->nome,
                'plataforma' => $s->plataforma,
                'pedido_em' => $s->updated_at,
            ])->values(),
        ]);
    }

    /**
     * Lista completa de empresas — restrito a SUPERADMIN (middleware 'user_type:SUPERADMIN' na
     * rota). Empresa não é Model tenant-aware (não usa BelongsToEmpresa — ela É o tenant), a
     * query já retorna todas as empresas sem precisar de withoutGlobalScope().
     */
    public function indexSuperadmin(): JsonResponse
    {
        $empresas = Empresa::orderBy('nome_fantasia')->get();
        // Última atividade e usuários ativos em 7/30/90 dias (docs/52 §4.2) — a carteira inteira
        // numa tela, sem abrir empresa por empresa.
        Adesao::anexarEmpresas($empresas);

        return response()->json([
            'empresas' => EmpresaResource::collection($empresas),
        ]);
    }

    /**
     * Detalhe de uma empresa + números de uso pra página de gestão do SUPERADMIN (licenças,
     * usuários, PDVs, atividade) — ver docs/03-ADMIN-WEB.md#8-empresas-empresas-só-superadmin.
     * withoutGlobalScopes() explícito "pela clareza de intenção" — o scope de tenant já não
     * filtra nada pro SUPERADMIN (empresa_id null), mesmo padrão de UsuarioController::store.
     */
    public function showSuperadmin(Empresa $empresa): JsonResponse
    {
        $visitasQuery = Visita::withoutGlobalScopes()->where('empresa_id', $empresa->id);

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
            'uso' => [
                ...UsoLicencas::resumo($empresa),
                'visitas_total' => (clone $visitasQuery)->count(),
                'visitas_ultimos_30_dias' => (clone $visitasQuery)->where('inicio_data', '>=', now()->subDays(30))->count(),
                'pontos_venda_visitados' => (clone $visitasQuery)->distinct('ponto_venda_id')->count('ponto_venda_id'),
                'ultima_atividade_em' => (clone $visitasQuery)->max('inicio_data'),
                // Uso do sistema (não só visita) — docs/52 §4.2.
                'adesao' => Adesao::porEmpresa([$empresa->id])[$empresa->id],
            ],
        ]);
    }

    /**
     * Parâmetros padrão da empresa (App\Support\ParametrosPadrao) — quais já estão cadastrados e
     * quais faltam, pro card "Parâmetros padrão" no detalhe da empresa do superadmin.
     */
    public function parametrosPadrao(Empresa $empresa): JsonResponse
    {
        return response()->json(['parametros' => ParametrosPadrao::situacao($empresa)]);
    }

    /**
     * Botão "Completar parâmetros": cadastra só os que faltam, com o valor que o sistema já assume
     * quando falta (não muda comportamento da empresa, só deixa visível/editável). Nunca
     * sobrescreve nem reativa um existente. Idempotente.
     */
    public function completarParametrosPadrao(Empresa $empresa): JsonResponse
    {
        $criados = ParametrosPadrao::completar($empresa);

        return response()->json([
            'criados' => $criados,
            'parametros' => ParametrosPadrao::situacao($empresa),
        ]);
    }

    /**
     * Provisiona uma empresa cliente manualmente (suporte cadastrando por fora do self-serve)
     * já com o primeiro ADMIN — sem isso não haveria como logar nela depois, e o signup
     * público sempre cria uma empresa nova, nunca anexa a uma já existente.
     */
    public function storeSuperadmin(StoreEmpresaSuperadminRequest $request): JsonResponse
    {
        $dados = $request->validated();

        [$empresa, $usuario] = DB::transaction(function () use ($dados) {
            $empresa = Empresa::create([
                'razao_social' => $dados['razao_social'],
                'nome_fantasia' => $dados['nome_fantasia'],
                'cnpj' => $dados['cnpj'],
                'plano' => $dados['plano'],
                'limite_usuarios' => $dados['limite_usuarios'] ?? null,
                'limite_pontos_venda' => $dados['limite_pontos_venda'] ?? null,
                'limite_licencas' => $dados['limite_licencas'] ?? null,
                'pedidos_venda_habilitado' => $dados['pedidos_venda_habilitado'] ?? false,
                'faturamento_contratado' => $dados['faturamento_contratado'] ?? false,
                'controle_xp_habilitado' => $dados['controle_xp_habilitado'] ?? false,
                'fuso' => $dados['fuso'] ?? Fuso::PADRAO,
            ]);
            if ($empresa->controle_xp_habilitado) {
                ClassePontoVenda::semearExemplo($empresa);
            }

            $usuario = Usuario::create([
                'empresa_id' => $empresa->id,
                'nome' => $dados['admin_nome'],
                'email' => $dados['admin_email'],
                'senha_hash' => Hash::make($dados['admin_senha']),
                'user_type' => UserType::ADMIN,
            ]);

            TipoRegistro::seedPadrao($empresa->id);
            // Empresa cadastrada pelo suporte já nasce com todos os parâmetros visíveis na tela.
            ParametrosPadrao::completar($empresa);
            RelatoriosPadrao::completar($empresa);

            return [$empresa, $usuario];
        });

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
            'usuario' => new UsuarioResource($usuario),
        ], 201);
    }

    /**
     * Edita dados/plano/limites da empresa, ou reativa uma empresa bloqueada (ativo: true) —
     * o bloqueio em si é via destroySuperadmin.
     */
    public function updateSuperadmin(UpdateEmpresaSuperadminRequest $request, Empresa $empresa): JsonResponse
    {
        $empresa->update($request->validated());
        // Régua de XP ligada numa empresa sem classes: ganha as de exemplo pra calibrar (docs/88 §4.1).
        if ($empresa->controle_xp_habilitado) {
            ClassePontoVenda::semearExemplo($empresa);
        }

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
        ]);
    }

    /**
     * "Bloquear" empresa — soft delete (ativo = false), mesmo padrão do resto da API, nunca
     * hard delete. Revoga na hora os tokens de todos os usuários dela: sem isso, quem já
     * estivesse logado continuaria com acesso normal até o token expirar (mesmo raciocínio de
     * UsuarioController::destroy, só que pra empresa inteira). O AuthController::login também
     * passa a recusar login de usuário de empresa bloqueada.
     */
    public function destroySuperadmin(Empresa $empresa): JsonResponse
    {
        DB::transaction(function () use ($empresa) {
            $empresa->update(['ativo' => false]);

            $usuarioIds = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('id');

            // Delete em massa (1 query) em vez de 1 DELETE por usuário — antes, uma empresa com
            // muitos usuários demorava proporcionalmente mais pra bloquear, e uma falha no meio
            // do loop deixava a empresa já marcada `ativo=false` com só parte dos tokens
            // revogados (bloqueio parcial, sem transação nenhuma amarrando os dois).
            DB::table('personal_access_tokens')
                ->where('tokenable_type', Usuario::class)
                ->whereIn('tokenable_id', $usuarioIds)
                ->delete();
        });

        return response()->json(status: 204);
    }
}
