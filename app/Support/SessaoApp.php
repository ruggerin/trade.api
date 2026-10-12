<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Models\Usuario;
use Illuminate\Http\Request;

/**
 * Sessões (tokens Sanctum) por canal — docs/67 §4.
 *
 * - `mobile`: nasce no login do app, preso ao aparelho vinculado (dispositivo_id), vale
 *   VALIDADE_DIAS e é renovado por /auth/refresh (token novo, antigo marcado `rotacionado_em`).
 * - `web`: admin web, sem validade nem trava nesta fase (docs/67 §6, "Futura").
 * - null: token anterior à doc 67 (legado) — aceito em tudo até o próximo login.
 *
 * Revogar não apaga: marca `revogado_em` + motivo, pra resposta 401 dizer por que a sessão caiu
 * ("Sua conta entrou em outro aparelho (Moto G, 14:32)"). O comando sessoes:limpar apaga depois.
 */
class SessaoApp
{
    public const CANAL_WEB = 'web';

    public const CANAL_MOBILE = 'mobile';

    public const VALIDADE_DIAS = 7;

    /**
     * Quanto tempo o token antigo segue valendo depois de um refresh — cobre as requisições que já
     * estavam em voo com ele (fila offline, upload de foto). Passado isso, usar o antigo = cópia.
     */
    public const TOLERANCIA_ROTACAO_SEGUNDOS = 60;

    public const MOTIVO_NOVO_LOGIN = 'NOVO_LOGIN';

    public const MOTIVO_OUTRO_APARELHO = 'OUTRO_APARELHO';

    public const MOTIVO_APARELHO_DESVINCULADO = 'APARELHO_DESVINCULADO';

    public const MOTIVO_TOKEN_REAPROVEITADO = 'TOKEN_REAPROVEITADO';

    public const MOTIVO_SEM_LICENCA = 'SEM_LICENCA';

    /** Atributo do request onde tokenAceito() deixa o motivo pro handler do 401 (bootstrap/app.php). */
    public const ATRIBUTO_SESSAO_ENCERRADA = 'sessao_encerrada';

    /**
     * PROMOTOR só entra pelo app. Os demais são `mobile` quando o app se identifica (header
     * X-Client, o mesmo da medição de uso da docs/52, ou o identificador do aparelho que só o app
     * manda); sem isso é o admin web.
     */
    public static function canalDoLogin(Request $request, Usuario $usuario): string
    {
        if ($usuario->user_type === UserType::PROMOTOR
            || strtolower((string) $request->header('X-Client')) === 'mobile'
            || $request->filled('dispositivo_identificador')) {
            return self::CANAL_MOBILE;
        }

        return self::CANAL_WEB;
    }

    /** Cria o token já marcado com canal, aparelho, IP e user agent; só o `mobile` expira. */
    public static function emitir(Usuario $usuario, string $canal, Request $request, ?Dispositivo $dispositivo = null): string
    {
        $novo = $usuario->createToken(
            'acesso-api',
            ['*'],
            $canal === self::CANAL_MOBILE ? now()->addDays(self::VALIDADE_DIAS) : null,
        );

        $novo->accessToken->forceFill([
            'canal' => $canal,
            'dispositivo_id' => $dispositivo?->id,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ])->save();

        return $novo->plainTextToken;
    }

    /**
     * Derruba as sessões do app (e as legadas) do usuário, mantendo as do admin web — um ADMIN
     * logando no celular não pode perder a aba aberta no computador.
     */
    public static function revogarMobile(Usuario $usuario, string $motivo, array $detalhe = []): void
    {
        $usuario->tokens()
            ->where(fn ($q) => $q->where('canal', self::CANAL_MOBILE)->orWhereNull('canal'))
            ->whereNull('revogado_em')
            ->update([
                'revogado_em' => now(),
                'revogado_motivo' => $motivo,
                'revogado_detalhe' => $detalhe ? json_encode($detalhe) : null,
            ]);
    }

    /**
     * Gancho do Sanctum (Sanctum::authenticateAccessTokensUsing, ver AppServiceProvider):
     * - token revogado → recusa e guarda o motivo pro 401;
     * - token rotacionado vale só durante a tolerância; depois disso, alguém está usando uma cópia —
     *   derruba todas as sessões mobile do usuário pra forçar login de novo no aparelho vinculado.
     */
    public static function tokenAceito(PersonalAccessToken $token, bool $valido): bool
    {
        if (! $valido) {
            return false;
        }

        if ($token->revogado_em !== null) {
            request()->attributes->set(self::ATRIBUTO_SESSAO_ENCERRADA, [
                'motivo' => $token->revogado_motivo,
                'detalhe' => $token->revogado_detalhe,
            ]);

            return false;
        }

        if ($token->rotacionado_em === null
            || $token->rotacionado_em->gt(now()->subSeconds(self::TOLERANCIA_ROTACAO_SEGUNDOS))) {
            return true;
        }

        self::registrarReuso($token);
        request()->attributes->set(self::ATRIBUTO_SESSAO_ENCERRADA, ['motivo' => self::MOTIVO_TOKEN_REAPROVEITADO, 'detalhe' => null]);

        return false;
    }

    public static function registrarReuso(PersonalAccessToken $token): void
    {
        // Sem o scope de tenant: isto roda dentro da própria autenticação do Sanctum, e o scope
        // (BelongsToEmpresa) consultaria o guard que ainda está resolvendo o usuário.
        $usuario = Usuario::withoutGlobalScopes()->find($token->tokenable_id);
        if (! $usuario instanceof Usuario) {
            return;
        }

        self::revogarMobile($usuario, self::MOTIVO_TOKEN_REAPROVEITADO);
        Seguranca::registrar(Seguranca::TOKEN_REAPROVEITADO, $usuario, $token->dispositivo_id ? Dispositivo::find($token->dispositivo_id) : null, [
            'token_id' => $token->id,
        ]);
    }

    /**
     * Último uso do app em cada aparelho: o mais recente entre o login nele (ultimo_acesso_em) e o
     * uso de qualquer token emitido pra ele, inclusive os já renovados ou revogados — cada login e
     * cada refresh troca o token, então olhar só o atual mostraria "nunca" logo depois de entrar.
     *
     * @param  iterable<Dispositivo>  $dispositivos
     * @return array<int, \Carbon\CarbonInterface|null> por id do dispositivo
     */
    public static function ultimoUsoPorAparelho(iterable $dispositivos): array
    {
        $porId = collect($dispositivos)->filter()->keyBy('id');
        if ($porId->isEmpty()) {
            return [];
        }

        $tokens = \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->whereIn('dispositivo_id', $porId->keys())
            ->groupBy('dispositivo_id')
            ->selectRaw('dispositivo_id, max(last_used_at) as ultimo')
            ->pluck('ultimo', 'dispositivo_id');

        return $porId->map(function (Dispositivo $d) use ($tokens) {
            $token = isset($tokens[$d->id]) ? \Carbon\Carbon::parse($tokens[$d->id]) : null;

            return collect([$token, $d->ultimo_acesso_em])->filter()->max();
        })->all();
    }

    /** Usou o app nos últimos 5 minutos (indicador "online" das telas de licença). */
    public static function online(?\Carbon\CarbonInterface $ultimoUso): bool
    {
        return $ultimoUso !== null && $ultimoUso->gt(now()->subMinutes(5));
    }

    /** Texto da tela de login do app quando a sessão cai (docs/67 §4.5). */
    public static function mensagem(?string $motivo, ?array $detalhe): string
    {
        $aparelho = $detalhe['aparelho'] ?? null;
        // `hora` já vem no fuso da empresa (VinculoAparelho::desvincular).
        $quando = $detalhe['hora'] ?? null;
        $onde = trim(($aparelho ?? 'outro aparelho').($quando ? ", {$quando}" : ''));

        return match ($motivo) {
            self::MOTIVO_OUTRO_APARELHO => "Sua conta entrou em outro aparelho ({$onde}). Se não foi você, avise seu gestor.",
            self::MOTIVO_APARELHO_DESVINCULADO => 'Este aparelho foi desvinculado da sua conta pelo gestor.',
            self::MOTIVO_TOKEN_REAPROVEITADO => 'Sua sessão foi encerrada por segurança. Entre de novo.',
            self::MOTIVO_SEM_LICENCA => 'Sua licença do app foi removida. Fale com seu gestor.',
            self::MOTIVO_NOVO_LOGIN => 'Você entrou de novo neste aparelho; a sessão anterior foi encerrada.',
            default => 'Sessão encerrada. Entre de novo.',
        };
    }
}
