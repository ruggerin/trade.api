<?php

namespace App\Support;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\SolicitacaoAparelho;
use App\Models\Usuario;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Regra de vínculo e troca de aparelho no app (docs/67 §4.3, decisão de 12/10/2026: troca
 * self-service 1× a cada 7 dias; fora disso, 423 e o ADMIN libera).
 *
 * - Mesmo aparelho vinculado → segue (só atualiza modelo/versões).
 * - Nenhum aparelho vinculado → vincula este. Se o ADMIN aprovou uma solicitação, só o aparelho
 *   aprovado entra.
 * - Aparelho diferente → troca self-service se a janela permite; senão 423 APARELHO_BLOQUEADO e
 *   uma solicitação pendente pro ADMIN.
 *
 * Parâmetros por empresa: TROCA_APARELHO_DIAS (padrão e piso 7 — a empresa só endurece),
 * TROCA_APARELHO_EXIGE_APROVACAO (toda troca passa pelo ADMIN), TROCA_APARELHO_SO_REGISTRA (troca
 * sempre, mas grava o que seria bloqueado — pro período de medição antes de ligar o bloqueio,
 * docs/67 §7.3).
 */
class VinculoAparelho
{
    public const PISO_DIAS_TROCA = 7;

    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    /**
     * @param  array{identificador: string, nome?: ?string, plataforma?: ?string, versao_so?: ?string, versao_app?: ?string}  $aparelho
     *
     * @throws HttpResponseException 423 quando o aparelho não pode entrar
     */
    public static function resolver(Usuario $usuario, array $aparelho): Dispositivo
    {
        // A decisão roda numa transação; a negação (solicitação + evento + 423) é gravada depois,
        // fora dela — senão o throw desfaria o próprio pedido de liberação.
        $resultado = DB::transaction(function () use ($usuario, $aparelho): Dispositivo|array {
            // Serializa logins simultâneos do mesmo usuário (dois aparelhos ao mesmo tempo).
            Usuario::withoutGlobalScopes()->whereKey($usuario->id)->lockForUpdate()->first();

            $hash = Dispositivo::hashIdentificador($aparelho['identificador']);
            $ativo = $usuario->dispositivo()->first();
            $metadados = array_filter([
                'nome' => $aparelho['nome'] ?? null,
                'plataforma' => $aparelho['plataforma'] ?? null,
                'versao_so' => $aparelho['versao_so'] ?? null,
                'versao_app' => $aparelho['versao_app'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            if ($ativo && $ativo->identificador_hash === $hash) {
                $ativo->update([...$metadados, 'ultimo_acesso_em' => now()]);

                return $ativo;
            }

            $aprovada = SolicitacaoAparelho::withoutGlobalScopes()
                ->where('usuario_id', $usuario->id)
                ->where('status', SolicitacaoAparelho::APROVADA)
                ->latest('decidido_em')
                ->first();

            if (! $ativo) {
                if ($aprovada && $aprovada->identificador_hash !== $hash) {
                    return [$hash, $metadados, 'Outro aparelho foi liberado pelo seu gestor para esta conta.', null];
                }

                $aprovada?->update(['status' => SolicitacaoAparelho::USADA]);
                $usuario->forceFill(['troca_aparelho_liberada_em' => null])->save();
                $jaTeveAparelho = $usuario->dispositivos()->exists();
                $novo = self::vincular($usuario, $hash, $metadados, $jaTeveAparelho ? Dispositivo::ORIGEM_LIBERADO_ADMIN : Dispositivo::ORIGEM_PRIMEIRO);
                Seguranca::registrar(Seguranca::APARELHO_VINCULADO, $usuario, $novo, ['origem' => $novo->origem]);

                return $novo;
            }

            // Troca extra liberada pelo ADMIN ("Liberar nova troca"): entra sem pedido e sem gastar a
            // troca própria da janela; a liberação vale uma vez.
            if ($usuario->troca_aparelho_liberada_em !== null) {
                self::desvincular($ativo, 'TROCA_LIBERADA', null, ['aparelho' => $metadados['nome'] ?? null]);
                $usuario->forceFill(['troca_aparelho_liberada_em' => null])->save();
                $novo = self::vincular($usuario, $hash, $metadados, Dispositivo::ORIGEM_LIBERADO_ADMIN);
                Seguranca::registrar(Seguranca::TROCA_APARELHO, $usuario, $novo, [
                    'anterior' => $ativo->nome, 'novo' => $novo->nome, 'liberada_pelo_admin' => true,
                ]);

                return $novo;
            }

            $empresa = $usuario->empresa;
            $podeTrocar = $empresa === null || self::trocaPropriaDisponivel($usuario, $empresa);
            $soRegistra = $empresa !== null && self::parametroVerdadeiro($empresa, 'TROCA_APARELHO_SO_REGISTRA');

            if (! $podeTrocar && ! $soRegistra) {
                return [$hash, $metadados, 'Este aparelho ainda não está liberado. Pedimos ao seu gestor para liberar.', $ativo];
            }

            self::desvincular($ativo, $podeTrocar ? 'TROCA_PROPRIA' : 'TROCA_SO_REGISTRA', null, [
                'aparelho' => $metadados['nome'] ?? null,
            ]);
            $novo = self::vincular($usuario, $hash, $metadados, Dispositivo::ORIGEM_TROCA_PROPRIA);
            Seguranca::registrar(
                $podeTrocar ? Seguranca::TROCA_APARELHO : Seguranca::TROCA_SERIA_BLOQUEADA,
                $usuario,
                $novo,
                ['anterior' => $ativo->nome, 'novo' => $novo->nome],
            );

            return $novo;
        });

        if (is_array($resultado)) {
            self::negar($usuario, ...$resultado);
        }

        return $resultado;
    }

    /**
     * Encerra o vínculo (histórico fica) e derruba as sessões do app presas a ele, com motivo pro
     * app explicar a saída.
     */
    public static function desvincular(Dispositivo $dispositivo, string $motivo, ?Usuario $por, array $detalheSessao = []): void
    {
        $dispositivo->update([
            'desvinculado_em' => now(),
            'desvinculado_por' => $por?->id,
            'motivo' => $motivo,
        ]);

        // Sem scope de tenant: roda no login, antes de existir usuário autenticado.
        $dono = Usuario::withoutGlobalScopes()->findOrFail($dispositivo->usuario_id);
        // Hora já no fuso da empresa (docs/50) — a mensagem do app mostrava UTC (achado no teste
        // no celular de 12/10/2026: "20:11" com o relógio marcando 16:11).
        $detalheSessao['hora'] = now()->setTimezone(Fuso::daEmpresa($dono->empresa))->format('H:i');
        SessaoApp::revogarMobile($dono, match ($motivo) {
            'TROCA_PROPRIA', 'TROCA_SO_REGISTRA', 'TROCA_LIBERADA' => SessaoApp::MOTIVO_OUTRO_APARELHO,
            'LICENCA_REMOVIDA' => SessaoApp::MOTIVO_SEM_LICENCA,
            default => SessaoApp::MOTIVO_APARELHO_DESVINCULADO,
        }, [...$detalheSessao, 'em' => now()->toIso8601String()]);
    }

    /** Dias entre trocas self-service — nunca abaixo do piso; 0 no parâmetro não afrouxa. */
    public static function diasEntreTrocas(Empresa $empresa): int
    {
        $valor = (int) (self::parametro($empresa, 'TROCA_APARELHO_DIAS') ?? self::PISO_DIAS_TROCA);

        return max(self::PISO_DIAS_TROCA, $valor);
    }

    /** Quando a troca própria volta a valer; null = já disponível (ou a empresa exige aprovação sempre). */
    public static function proximaTrocaPropriaEm(Usuario $usuario, Empresa $empresa): ?\Carbon\CarbonInterface
    {
        $ultima = $usuario->dispositivos()
            ->where('origem', Dispositivo::ORIGEM_TROCA_PROPRIA)
            ->max('vinculado_em');
        if ($ultima === null) {
            return null;
        }

        $proxima = \Carbon\Carbon::parse($ultima)->addDays(self::diasEntreTrocas($empresa));

        return $proxima->isFuture() ? $proxima : null;
    }

    public static function trocaPropriaDisponivel(Usuario $usuario, Empresa $empresa): bool
    {
        if (self::parametroVerdadeiro($empresa, 'TROCA_APARELHO_EXIGE_APROVACAO')) {
            return false;
        }

        return ! $usuario->dispositivos()
            ->where('origem', Dispositivo::ORIGEM_TROCA_PROPRIA)
            ->where('vinculado_em', '>=', now()->subDays(self::diasEntreTrocas($empresa)))
            ->exists();
    }

    private static function vincular(Usuario $usuario, string $hash, array $metadados, string $origem): Dispositivo
    {
        return Dispositivo::create([
            ...$metadados,
            'usuario_id' => $usuario->id,
            'empresa_id' => $usuario->empresa_id,
            'identificador_hash' => $hash,
            'origem' => $origem,
            'vinculado_em' => now(),
            'ultimo_acesso_em' => now(),
        ]);
    }

    /** @return never */
    private static function negar(Usuario $usuario, string $hash, array $metadados, string $mensagem, ?Dispositivo $ativo = null): void
    {
        if ($usuario->empresa_id !== null) {
            $solicitacao = SolicitacaoAparelho::withoutGlobalScopes()->firstOrNew([
                'usuario_id' => $usuario->id,
                'identificador_hash' => $hash,
                'status' => SolicitacaoAparelho::PENDENTE,
            ]);
            $solicitacao->fill([
                'empresa_id' => $usuario->empresa_id,
                'nome' => $metadados['nome'] ?? $solicitacao->nome,
                'plataforma' => $metadados['plataforma'] ?? $solicitacao->plataforma,
            ]);
            $solicitacao->updated_at = now();
            $solicitacao->save();
        }

        Seguranca::registrar(Seguranca::TROCA_BLOQUEADA, $usuario, $ativo, [
            'tentativa' => $metadados['nome'] ?? null,
            'vinculado' => $ativo?->nome,
        ]);

        throw new HttpResponseException(response()->json([
            'message' => $mensagem,
            'codigo' => 'APARELHO_BLOQUEADO',
            'aparelho_vinculado' => $ativo?->nome,
        ], 423));
    }

    private static function parametro(Empresa $empresa, string $chave): ?string
    {
        return Parametro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->value('valor');
    }

    private static function parametroVerdadeiro(Empresa $empresa, string $chave): bool
    {
        return in_array(strtolower((string) self::parametro($empresa, $chave)), self::VALORES_VERDADEIROS, true);
    }
}
