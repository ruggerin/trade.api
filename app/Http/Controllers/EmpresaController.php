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
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
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

            return [$empresa, $usuario];
        });

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
     * Lista completa de empresas — restrito a SUPERADMIN (middleware 'user_type:SUPERADMIN' na
     * rota). Empresa não é Model tenant-aware (não usa BelongsToEmpresa — ela É o tenant), a
     * query já retorna todas as empresas sem precisar de withoutGlobalScope().
     */
    public function indexSuperadmin(): JsonResponse
    {
        $empresas = Empresa::orderBy('nome_fantasia')->get();

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
        $licencasUsadas = Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('user_type', UserType::PROMOTOR)
            ->where('ativo', true)
            ->count();

        $usuariosTotal = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();

        $pontosVendaTotal = PontoVenda::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('ativo', true)
            ->count();

        $visitasQuery = Visita::withoutGlobalScopes()->where('empresa_id', $empresa->id);

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
            'uso' => [
                'licencas_usadas' => $licencasUsadas,
                'licencas_limite' => $empresa->limite_licencas,
                'usuarios_total' => $usuariosTotal,
                'usuarios_limite' => $empresa->limite_usuarios,
                'pontos_venda_total' => $pontosVendaTotal,
                'pontos_venda_limite' => $empresa->limite_pontos_venda,
                'visitas_total' => (clone $visitasQuery)->count(),
                'visitas_ultimos_30_dias' => (clone $visitasQuery)->where('inicio_data', '>=', now()->subDays(30))->count(),
                'pontos_venda_visitados' => (clone $visitasQuery)->distinct('ponto_venda_id')->count('ponto_venda_id'),
                'ultima_atividade_em' => (clone $visitasQuery)->max('inicio_data'),
            ],
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
            ]);

            $usuario = Usuario::create([
                'empresa_id' => $empresa->id,
                'nome' => $dados['admin_nome'],
                'email' => $dados['admin_email'],
                'senha_hash' => Hash::make($dados['admin_senha']),
                'user_type' => UserType::ADMIN,
            ]);

            TipoRegistro::seedPadrao($empresa->id);

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
