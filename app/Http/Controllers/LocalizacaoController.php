<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Usuario;
use App\Support\Instante;
use App\Support\OperacaoDoDia;
use App\Support\Rastreamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rastreamento em tempo real — ver docs/11-RASTREAMENTO-TEMPO-REAL.md. Só a posição mais recente
 * de cada promotor (3 colunas em `usuarios`), sem histórico.
 */
class LocalizacaoController extends Controller
{
    /**
     * O próprio promotor manda a posição dele — sempre grava em cima do usuário autenticado (não
     * existe parâmetro de usuário, então não precisa de checagem de ownership). Idempotente.
     */
    public function atualizar(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless($usuario->user_type === UserType::PROMOTOR, 403, 'Só o promotor envia localização.');
        abort_unless(
            $usuario->empresa && Rastreamento::habilitado($usuario->empresa),
            403,
            'O rastreamento não está habilitado para a sua empresa.',
        );

        $dados = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Momento em que o aparelho capturou a posição (não quando chegou aqui) — opcional.
            'capturado_em' => ['nullable', 'date'],
        ]);

        // Instante sempre UTC (docs/50): um capturado_em com offset (-04:00) seria gravado com a
        // hora "de parede" daquele fuso se fosse direto pro banco.
        $capturadoEm = Instante::normalizar($dados['capturado_em'] ?? null) ?? now()->utc();
        // Relógio do aparelho adiantado não pode fazer a posição parecer "do futuro".
        if ($capturadoEm->isFuture()) {
            $capturadoEm = now();
        }

        // Rota do dia (docs/48 §4.1): toda leitura entra no histórico — inclusive um envio atrasado,
        // que é um ponto real do trajeto. insertOrIgnore: reenvio da mesma leitura não duplica.
        DB::table('localizacoes_historico')->insertOrIgnore([
            'empresa_id' => $usuario->empresa_id,
            'usuario_id' => $usuario->id,
            'latitude' => $dados['latitude'],
            'longitude' => $dados['longitude'],
            'capturado_em' => $capturadoEm,
        ]);

        // Um envio atrasado (rede lenta, reenvio) nunca sobrescreve uma posição mais recente.
        if ($usuario->ultima_localizacao_em && $usuario->ultima_localizacao_em->gt($capturadoEm)) {
            return response()->json(status: 204);
        }

        // Sem tocar em updated_at: posição chega a cada poucos segundos e não é edição do
        // cadastro — senão "atualizado recentemente" na lista de usuários viraria "está com o GPS
        // ligado", e o ?v= da foto (UsuarioResource::foto_url) invalidaria o cache a cada ping.
        $usuario->timestamps = false;
        $usuario->forceFill([
            'ultima_localizacao_latitude' => $dados['latitude'],
            'ultima_localizacao_longitude' => $dados['longitude'],
            'ultima_localizacao_em' => $capturadoEm,
        ])->save();

        return response()->json(status: 204);
    }

    /** Promotores da empresa que já compartilharam posição alguma vez (nunca-compartilhou não entra). */
    public function index(Request $request): JsonResponse
    {
        $limiteAtivo = now()->subMinutes(Rastreamento::JANELA_ATIVO_MINUTOS);

        $promotores = Usuario::query()
            ->where('user_type', UserType::PROMOTOR)
            ->where('ativo', true)
            ->whereNotNull('ultima_localizacao_em')
            ->orderBy('nome')
            ->get();

        return response()->json([
            'localizacoes' => $promotores->map(fn (Usuario $u) => [
                'id' => $u->uuid,
                'nome' => $u->nome,
                'foto_url' => $u->foto_path ? url("/api/usuarios/{$u->uuid}/foto") : null,
                'latitude' => $u->ultima_localizacao_latitude,
                'longitude' => $u->ultima_localizacao_longitude,
                'ultima_localizacao_em' => $u->ultima_localizacao_em,
                // Calculado na exibição, nunca gravado (doc 11 §3.1).
                'ativo_agora' => $u->ultima_localizacao_em->gte($limiteAtivo),
                // Situação informada pelo próprio app (docs/47 §5.1) — por que está ou não rastreando.
                'situacao' => $u->rastreamento_situacao,
            ])->values(),
        ]);
    }

    /**
     * O app do promotor informa POR QUE está (ou não) rastreando (docs/47 §5.4) — sempre que a
     * situação muda. Aceito mesmo com o rastreamento sem mandar posição: é justamente esse o caso
     * que interessa (permissão só durante o uso, GPS desligado...). Não mexe em updated_at.
     */
    public function situacao(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->user_type === UserType::PROMOTOR, 403, 'Só o promotor informa a situação do rastreamento.');

        $dados = $request->validate([
            'situacao' => ['required', Rule::in(Rastreamento::SITUACOES)],
            'detalhe' => ['nullable', 'string', 'max:255'],
        ]);

        $usuario->timestamps = false;
        $usuario->forceFill([
            'rastreamento_situacao' => $dados['situacao'],
            'rastreamento_situacao_detalhe' => $dados['detalhe'] ?? null,
            'rastreamento_situacao_em' => now(),
        ])->save();

        return response()->json(status: 204);
    }

    /**
     * Lista de promotores irregulares pro Mapa ao vivo (docs/47 §5.4) — só com
     * RASTREAMENTO_PAINEL_CONFORMIDADE ligado e dentro da jornada (se a empresa limita à jornada;
     * fora dela ninguém é cobrado). Irregular = o app informou um motivo diferente de ATIVO, ou
     * informou ATIVO mas não chega posição há mais que a tolerância (economia de bateria, app
     * fechado à força), ou nunca informou nada.
     */
    public function conformidade(Request $request): JsonResponse
    {
        $empresa = $request->user()->empresa;
        abort_unless($empresa !== null, 403);

        $painel = Rastreamento::habilitado($empresa) && Rastreamento::painelConformidade($empresa);
        $dentroDaJornada = Rastreamento::dentroDaJornada($empresa);
        $tolerancia = Rastreamento::toleranciaSemSinalMinutos($empresa);

        $irregulares = collect();
        if ($painel && $dentroDaJornada) {
            $limite = now()->subMinutes($tolerancia);

            $irregulares = Usuario::query()
                ->where('user_type', UserType::PROMOTOR)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get()
                ->map(function (Usuario $u) use ($limite) {
                    [$motivo, $desde] = match (true) {
                        $u->rastreamento_situacao === null => ['NUNCA_INFORMOU', null],
                        $u->rastreamento_situacao !== 'ATIVO' => [$u->rastreamento_situacao, $u->rastreamento_situacao_em],
                        $u->ultima_localizacao_em === null || $u->ultima_localizacao_em->lt($limite) => ['SEM_SINAL', $u->ultima_localizacao_em],
                        default => [null, null],
                    };

                    return $motivo === null ? null : [
                        'id' => $u->uuid,
                        'nome' => $u->nome,
                        'foto_url' => $u->foto_path ? url("/api/usuarios/{$u->uuid}/foto") : null,
                        'motivo' => $motivo,
                        'detalhe' => $u->rastreamento_situacao_detalhe,
                        'desde' => $desde,
                        'ultima_localizacao_em' => $u->ultima_localizacao_em,
                    ];
                })
                ->filter()
                ->values();
        }

        return response()->json([
            'habilitado' => $painel,
            'exigencia' => Rastreamento::exigencia($empresa),
            'dentro_da_jornada' => $dentroDaJornada,
            'jornada' => Rastreamento::soNaJornada($empresa) ? OperacaoDoDia::jornada($empresa) : null,
            'tolerancia_sem_sinal_minutos' => $tolerancia,
            'irregulares' => $irregulares,
        ]);
    }
}
