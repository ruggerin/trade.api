<?php

namespace App\Http\Controllers;

use App\Models\Dispositivo;
use App\Models\EventoSeguranca;
use App\Models\SolicitacaoAparelho;
use App\Models\Usuario;
use App\Support\Seguranca;
use App\Support\SessaoApp;
use App\Support\UsoLicencas;
use App\Support\VinculoAparelho;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ações sobre aparelhos e a trilha de segurança (docs/67 §3.2/§4.3). Decidir pedidos, liberar
 * troca e a seção do cadastro ficam sob usuarios.gerenciar (quem edita o usuário); a trilha da
 * empresa, sob user_type:ADMIN. SolicitacaoAparelho e Usuario têm scope de tenant (route model
 * binding já isola a empresa); EventoSeguranca não, então o filtro de empresa é explícito.
 */
class SegurancaAparelhoController extends Controller
{
    /**
     * Libera o aparelho pedido: o atual é desvinculado (a sessão dele cai) e só o aparelho aprovado
     * consegue entrar em seguida (VinculoAparelho::resolver).
     */
    public function aprovar(Request $request, SolicitacaoAparelho $solicitacao): JsonResponse
    {
        abort_unless($solicitacao->status === SolicitacaoAparelho::PENDENTE, 422, 'Esta solicitação já foi decidida.');

        $usuario = $solicitacao->usuario;
        if ($atual = $usuario->dispositivo) {
            VinculoAparelho::desvincular($atual, 'LIBERADO_ADMIN', $request->user(), ['aparelho' => $solicitacao->nome]);
        }

        // Aprovar um pedido encerra os outros pendentes do mesmo usuário.
        SolicitacaoAparelho::where('usuario_id', $usuario->id)
            ->where('status', SolicitacaoAparelho::PENDENTE)
            ->whereKeyNot($solicitacao->id)
            ->update(['status' => SolicitacaoAparelho::RECUSADA, 'decidido_por' => $request->user()->id, 'decidido_em' => now()]);

        $solicitacao->update([
            'status' => SolicitacaoAparelho::APROVADA,
            'decidido_por' => $request->user()->id,
            'decidido_em' => now(),
        ]);
        Seguranca::registrar(Seguranca::SOLICITACAO_APROVADA, $usuario, null, [
            'aparelho' => $solicitacao->nome,
            'por' => $request->user()->nome,
        ]);

        return response()->json(status: 204);
    }

    public function recusar(Request $request, SolicitacaoAparelho $solicitacao): JsonResponse
    {
        abort_unless($solicitacao->status === SolicitacaoAparelho::PENDENTE, 422, 'Esta solicitação já foi decidida.');

        $solicitacao->update([
            'status' => SolicitacaoAparelho::RECUSADA,
            'decidido_por' => $request->user()->id,
            'decidido_em' => now(),
        ]);
        Seguranca::registrar(Seguranca::SOLICITACAO_RECUSADA, $solicitacao->usuario, null, [
            'aparelho' => $solicitacao->nome,
            'por' => $request->user()->nome,
        ]);

        return response()->json(status: 204);
    }

    /**
     * Seção "Acesso ao app mobile" do cadastro do usuário (docs/67 §3): licença, uso da empresa,
     * aparelho vinculado, janela de troca e pedido de liberação pendente.
     */
    public function acessoApp(Usuario $usuario): JsonResponse
    {
        $empresa = $usuario->empresa;
        $aparelho = $usuario->dispositivo()->first();
        $ultimoUso = $aparelho ? (SessaoApp::ultimoUsoPorAparelho([$aparelho])[$aparelho->id] ?? null) : null;
        $solicitacao = SolicitacaoAparelho::where('usuario_id', $usuario->id)
            ->where('status', SolicitacaoAparelho::PENDENTE)
            ->latest('updated_at')
            ->first();

        return response()->json([
            'licenca_mobile' => (bool) $usuario->licenca_mobile,
            'uso' => $empresa ? UsoLicencas::resumo($empresa) : null,
            'dispositivo' => $aparelho ? [
                'nome' => $aparelho->nome,
                'plataforma' => $aparelho->plataforma,
                'versao_so' => $aparelho->versao_so,
                'versao_app' => $aparelho->versao_app,
                'integridade_status' => $aparelho->integridade_status,
                'origem' => $aparelho->origem,
                'vinculado_em' => $aparelho->vinculado_em,
                'ultimo_uso_em' => $ultimoUso,
                'online' => SessaoApp::online($ultimoUso),
            ] : null,
            'trocas_aparelho_30d' => $usuario->dispositivos()
                ->where('origem', '!=', Dispositivo::ORIGEM_PRIMEIRO)
                ->where('vinculado_em', '>=', now()->subDays(30))
                ->count(),
            'proxima_troca_propria_em' => $empresa ? VinculoAparelho::proximaTrocaPropriaEm($usuario, $empresa) : null,
            'troca_liberada' => $usuario->troca_aparelho_liberada_em !== null,
            'solicitacao' => $solicitacao ? [
                'uuid' => $solicitacao->uuid,
                'aparelho' => $solicitacao->nome,
                'plataforma' => $solicitacao->plataforma,
                'pedido_em' => $solicitacao->updated_at,
            ] : null,
        ]);
    }

    /**
     * "Liberar nova troca": o próximo aparelho novo do usuário entra sem pedir aprovação e sem gastar
     * a troca própria — o atual segue funcionando até lá (diferente de desvincular, que derruba já).
     */
    public function liberarTroca(Request $request, Usuario $usuario): JsonResponse
    {
        abort_unless($usuario->licenca_mobile, 422, 'Este usuário não tem licença do app.');

        $usuario->forceFill(['troca_aparelho_liberada_em' => now()])->save();
        Seguranca::registrar(Seguranca::TROCA_LIBERADA, $usuario, $usuario->dispositivo()->first(), [
            'por' => $request->user()->nome,
        ]);

        return response()->json(status: 204);
    }

    /** Trilha de segurança da empresa, mais recente primeiro; filtra por usuário e tipo. */
    public function eventos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'usuario_uuid' => ['nullable', 'uuid'],
            'tipo' => ['nullable', 'string', 'max:40'],
        ]);

        $eventos = EventoSeguranca::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->when($dados['usuario_uuid'] ?? null, fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->where('uuid', $uuid)))
            ->when($dados['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->with(['usuario:id,uuid,nome', 'dispositivo:id,nome'])
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'eventos' => collect($eventos->items())->map(fn (EventoSeguranca $e): array => [
                'uuid' => $e->uuid,
                'tipo' => $e->tipo,
                'usuario_uuid' => $e->usuario?->uuid,
                'usuario_nome' => $e->usuario?->nome,
                'aparelho' => $e->dispositivo?->nome,
                'detalhes' => $e->detalhes,
                'ip' => $e->ip,
                'em' => $e->created_at,
            ]),
            'meta' => ['pagina' => $eventos->currentPage(), 'ultima_pagina' => $eventos->lastPage(), 'total' => $eventos->total()],
        ]);
    }
}
