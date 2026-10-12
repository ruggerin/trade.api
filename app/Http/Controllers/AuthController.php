<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Usuario\AtualizarFotoUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Models\Usuario;
use App\Models\UsuarioLoginLog;
use App\Support\Atestacao\Veredito;
use App\Support\AtestacaoAparelho;
use App\Support\DocumentosLegais;
use App\Support\Seguranca;
use App\Support\SessaoApp;
use App\Support\VinculoAparelho;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credenciais = $request->validated();

        $usuario = Usuario::withoutGlobalScopes()
            ->where('email', $credenciais['email'])
            ->first();

        if (! $usuario || ! Hash::check($credenciais['senha'], $usuario->senha_hash)) {
            if ($usuario) {
                Seguranca::registrar(Seguranca::LOGIN_NEGADO, $usuario, null, ['motivo' => 'SENHA']);
            }

            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        if (! $usuario->ativo) {
            throw ValidationException::withMessages([
                'email' => ['Este usuário está desativado.'],
            ]);
        }

        // SUPERADMIN não pertence a empresa nenhuma (empresa_id null) — só usuário de empresa
        // cliente pode ser barrado por bloqueio de empresa. Ver EmpresaController::destroySuperadmin.
        if ($usuario->empresa_id !== null && ! $usuario->empresa->ativo) {
            throw ValidationException::withMessages([
                'email' => ['A empresa deste usuário está bloqueada. Fale com o suporte.'],
            ]);
        }

        // Trava de 1 aparelho por usuário no app (docs/67 §4): vale pra qualquer user_type que
        // entra pelo app, não só PROMOTOR. Aparelho novo só entra pela troca self-service ou pela
        // liberação do ADMIN (App\Support\VinculoAparelho, 423 fora disso). O admin web
        // (canal `web`) segue sem trava nesta fase, mas o token dele não passa nas rotas do app
        // (middleware canal.mobile) — senão bastava logar pelo navegador e usar o token no celular.
        $canal = SessaoApp::canalDoLogin($request, $usuario);

        if ($canal === SessaoApp::CANAL_MOBILE) {
            if (empty($credenciais['dispositivo_identificador'])) {
                throw ValidationException::withMessages([
                    'dispositivo_identificador' => ['Obrigatório para login do app mobile.'],
                ]);
            }

            // Licença do app atribuída pelo ADMIN (docs/67 §3) — sem ela, nem vincula aparelho.
            if (! $usuario->licenca_mobile) {
                // Rastro pro ADMIN: quem tentou usar o app sem licença (achado no teste no celular).
                Seguranca::registrar(Seguranca::LOGIN_NEGADO, $usuario, null, ['motivo' => 'SEM_LICENCA']);

                return response()->json([
                    'message' => 'Você não tem licença do app Horus. Fale com seu gestor.',
                    'codigo' => 'SEM_LICENCA',
                ], 403);
            }

            // Fase 3 (docs/67): root/emulador e atestação do app ANTES de vincular — aparelho que não
            // passa não pode ocupar o vínculo. Desligada a obrigatoriedade, só gera alerta.
            $sinal = AtestacaoAparelho::avaliarSinais($usuario, $credenciais['integridade'] ?? null);
            $mesmoAparelho = $usuario->dispositivo?->identificador_hash === Dispositivo::hashIdentificador($credenciais['dispositivo_identificador'])
                ? $usuario->dispositivo
                : null;
            $veredito = AtestacaoAparelho::exigir($usuario, $credenciais['atestacao'] ?? null, $credenciais['chave_publica'] ?? null, $mesmoAparelho);

            $dispositivo = VinculoAparelho::resolver($usuario, [
                'identificador' => $credenciais['dispositivo_identificador'],
                'nome' => $credenciais['dispositivo_nome'] ?? null,
                'plataforma' => $credenciais['dispositivo_plataforma'] ?? null,
                'versao_so' => $credenciais['dispositivo_versao_so'] ?? null,
                'versao_app' => $request->header('X-App-Version'),
            ]);
            AtestacaoAparelho::aplicar($dispositivo, $veredito, $credenciais['chave_publica'] ?? null, $sinal);

            // Revoga as sessões anteriores do app — garante no máximo 1 sessão mobile válida,
            // mesmo se for o mesmo aparelho logando de novo. As do admin web ficam.
            SessaoApp::revogarMobile($usuario, SessaoApp::MOTIVO_NOVO_LOGIN);
        }

        // Log append-only pro histórico de eventos do usuário no admin web (ver
        // UsuarioController::historico) — não confundir com Dispositivo acima, que só guarda o
        // último acesso.
        UsuarioLoginLog::create([
            'usuario_id' => $usuario->id,
            // Só o hash (docs/67 §4.2) — o identificador puro do aparelho nunca é guardado.
            'dispositivo_identificador' => isset($credenciais['dispositivo_identificador'])
                ? Dispositivo::hashIdentificador($credenciais['dispositivo_identificador'])
                : null,
            'dispositivo_nome' => $credenciais['dispositivo_nome'] ?? null,
        ]);

        $token = SessaoApp::emitir($usuario, $canal, $request, $dispositivo ?? null);
        Seguranca::registrar(Seguranca::LOGIN, $usuario, $dispositivo ?? null, ['canal' => $canal]);
        // Termos/Política pendentes (docs/58 §5.3) — os apps mostram a tela de aceite antes de entrar.
        $usuario->documentosPendentes = DocumentosLegais::pendentes($usuario);

        return response()->json([
            'token' => $token,
            // `perfil` + `empresa` carregados pra o mobile já saber as permissões e os módulos
            // contratados (ex.: aba "Pedidos" do modo Vendedor, docs/38-PEDIDO-VENDEDOR.md §4/§12)
            // sem esperar um /auth/me.
            'usuario' => new UsuarioResource($usuario->loadMissing(['perfil', 'empresa'])),
        ]);
    }

    /**
     * Renova o token do app (docs/67 §4.4): token novo com mais VALIDADE_DIAS e o atual marcado
     * como rotacionado (segue valendo só pela tolerância das requisições em voo). Renovar de novo
     * com um token já rotacionado é sinal de cópia — derruba as sessões mobile do usuário.
     */
    public function refresh(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $atual = $usuario->currentAccessToken();

        if (! $atual instanceof PersonalAccessToken || $atual->canal === SessaoApp::CANAL_WEB) {
            return response()->json(['message' => 'Só sessões do app são renovadas.'], 403);
        }

        if ($atual->rotacionado_em !== null) {
            SessaoApp::registrarReuso($atual);

            return response()->json(['message' => 'Sessão encerrada. Entre de novo.'], 401);
        }

        $dispositivo = $atual->dispositivo_id ? Dispositivo::find($atual->dispositivo_id) : $usuario->dispositivo;

        // Fase 3: atestação também no refresh (docs/67 §5.3) — a chave é a já registrada no aparelho.
        if ($dispositivo) {
            $atestacao = $request->validate(['atestacao' => ['nullable', 'array']])['atestacao'] ?? null;
            $veredito = AtestacaoAparelho::exigir($usuario, $atestacao, $dispositivo->chave_publica, $dispositivo);
            if ($veredito->status !== Veredito::NAO_ENVIADA) {
                AtestacaoAparelho::aplicar($dispositivo, $veredito, null, null);
            }
        }

        $atual->forceFill(['rotacionado_em' => now()])->save();

        return response()->json([
            'token' => SessaoApp::emitir($usuario, SessaoApp::CANAL_MOBILE, $request, $dispositivo),
        ]);
    }

    /** Desafio de uso único pra atestação do app (docs/67 Fase 3), pedido antes do login/refresh. */
    public function desafio(): JsonResponse
    {
        return response()->json([
            ...AtestacaoAparelho::emitirDesafio(),
            // O app só tenta atestar quando sabe que o servidor está configurado pra isso.
            'obrigatoria' => AtestacaoAparelho::obrigatoria(),
            'play_integrity_projeto' => config('seguranca.play_integrity.numero_projeto_cloud') ?: null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user()->loadMissing(['empresa', 'perfil', 'dispositivo']);
        $usuario->documentosPendentes = DocumentosLegais::pendentes($usuario);

        return response()->json([
            'usuario' => new UsuarioResource($usuario),
        ]);
    }

    /**
     * Self-service — qualquer usuário autenticado troca a própria foto, sem exigir
     * `usuarios.gerenciar` (essa permissão é pra alguém mexer no cadastro de outro usuário).
     * Substitui o arquivo anterior em vez de acumular, mesmo raciocínio de
     * TipoRegistroController::sincronizarCampos (não deixa lixo órfão no disco).
     */
    public function atualizarFoto(AtualizarFotoUsuarioRequest $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario->foto_path) {
            Storage::disk(config('filesystems.default'))->delete($usuario->foto_path);
        }

        $caminho = $request->file('imagem')->store("usuarios/{$usuario->id}", config('filesystems.default'));
        $usuario->update(['foto_path' => $caminho]);

        return response()->json([
            'usuario' => new UsuarioResource($usuario->fresh()->loadMissing(['empresa', 'perfil', 'dispositivo'])),
        ]);
    }

    /**
     * Preferências do próprio usuário (docs/65) — hoje só o tema do admin. Self-service como a
     * foto: cada um decide o seu, sem permissão.
     */
    public function atualizarPreferencias(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tema' => ['required', Rule::in(['claro', 'escuro', 'sistema'])],
        ]);
        $usuario = $request->user();
        $usuario->update(['tema' => $dados['tema']]);

        return response()->json([
            'usuario' => new UsuarioResource($usuario->fresh()->loadMissing(['empresa', 'perfil', 'dispositivo'])),
        ]);
    }

    public function removerFoto(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario->foto_path) {
            Storage::disk(config('filesystems.default'))->delete($usuario->foto_path);
            $usuario->update(['foto_path' => null]);
        }

        return response()->json([
            'usuario' => new UsuarioResource($usuario->fresh()->loadMissing(['empresa', 'perfil', 'dispositivo'])),
        ]);
    }
}
