<?php

namespace App\Http\Controllers;

use App\Enums\StatusVisita;
use App\Models\PontoVenda;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Http\JsonResponse;

/**
 * "Histórico da loja" que o promotor consulta ao entrar (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md
 * §4.1): últimas visitas, rupturas e alertas já registrados naquele PDV, só leitura. Reaproveita
 * VisitaRegistro/Visita — sem schema novo. Os pedidos do ERP vêm de outro endpoint
 * (PedidoController::porPontoVenda).
 */
class HistoricoLojaController extends Controller
{
    private const LIMITE = 8;

    public function show(PontoVenda $pontoVenda): JsonResponse
    {
        $registros = fn () => VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereHas('visita', fn ($v) => $v->where('ponto_venda_id', $pontoVenda->id))
            ->with(['visita.usuario:id,nome', 'produtoAuditoria:id,descricao', 'tipoRegistro:id,descricao,icone'])
            ->latest('id')
            ->limit(self::LIMITE);

        $formatar = fn (VisitaRegistro $r) => [
            'id' => $r->uuid,
            'ocorrido_em' => $r->created_at,
            'promotor' => $r->visita->usuario?->nome,
            'tipo_registro' => $r->tipoRegistro?->descricao,
            'produto' => $r->produtoAuditoria?->descricao,
            'observacao' => $r->observacao,
        ];

        $rupturas = $registros()->where('ruptura', true)->get()->map($formatar)->values();
        $alertas = $registros()
            ->whereHas('tipoRegistro', fn ($t) => $t->where('eh_alerta', true))
            ->get()
            ->map(fn (VisitaRegistro $r) => $formatar($r) + ['resolvido' => $r->alerta_resolvido_em !== null])
            ->values();

        $visitas = Visita::query()
            ->where('ponto_venda_id', $pontoVenda->id)
            ->where('status', StatusVisita::FINALIZADA->value)
            ->with('usuario:id,nome')
            ->latest('inicio_data')
            ->limit(5)
            ->get()
            ->map(fn (Visita $v) => [
                'id' => $v->uuid,
                'inicio_data' => $v->inicio_data,
                'fim_data' => $v->fim_data,
                'promotor' => $v->usuario?->nome,
            ])
            ->values();

        return response()->json(['historico' => [
            'visitas' => $visitas,
            'rupturas' => $rupturas,
            'alertas' => $alertas,
        ]]);
    }
}
