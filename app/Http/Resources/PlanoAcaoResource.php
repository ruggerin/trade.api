<?php

namespace App\Http\Resources;

use App\Enums\StatusEtapaPlanoAcao;
use App\Models\PlanoAcaoEtapa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mesmo resource pra listagem e detalhe — `etapas`/`historico` só vêm quando carregados (o
 * index carrega etapas pro resumo/próxima etapa, só o show carrega historico). Ver
 * App\Http\Controllers\PlanoAcaoController e docs/37-PLANOS-DE-ACAO.md.
 *
 * @mixin \App\Models\PlanoAcao
 */
class PlanoAcaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $etapas = $this->relationLoaded('etapas') ? $this->etapas : null;
        $etapaAtrasada = $etapas?->contains(fn (PlanoAcaoEtapa $e) => $e->atrasada()) ?? false;
        $prazoVencido = $this->prazo !== null && $this->status->ativo() && $this->prazo->copy()->endOfDay()->isPast();

        return [
            'id' => $this->uuid,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'origem_tipo' => $this->origem_tipo,
            'origem' => $this->whenLoaded('origemRegistro', fn () => $this->origemRegistro ? [
                'registro_id' => $this->origemRegistro->uuid,
                'visita_id' => $this->origemRegistro->visita?->uuid,
                'tipo_registro' => $this->origemRegistro->tipoRegistro ? [
                    'descricao' => $this->origemRegistro->tipoRegistro->descricao,
                    'icone' => $this->origemRegistro->tipoRegistro->icone,
                ] : null,
                'produto' => $this->origemRegistro->produtoAuditoria?->descricao,
                'observacao' => $this->origemRegistro->observacao,
                'ponto_venda' => $this->origemRegistro->visita?->pontoVenda ? [
                    'id' => $this->origemRegistro->visita->pontoVenda->uuid,
                    'fantasia' => $this->origemRegistro->visita->pontoVenda->fantasia,
                ] : null,
                'promotor' => $this->origemRegistro->visita?->usuario?->nome,
                'registrado_em' => $this->origemRegistro->created_at,
                'alerta_resolvido_em' => $this->origemRegistro->alerta_resolvido_em,
            ] : null),
            // Escopo — loja (própria ou herdada do alerta) OU rede, ou nenhum dos dois.
            'ponto_venda' => $this->whenLoaded('pontoVenda', fn () => $this->pontoVenda ? [
                'id' => $this->pontoVenda->uuid,
                'fantasia' => $this->pontoVenda->fantasia,
                'rede' => $this->pontoVenda->relationLoaded('redeLoja') && $this->pontoVenda->redeLoja
                    ? ['id' => $this->pontoVenda->redeLoja->uuid, 'descricao' => $this->pontoVenda->redeLoja->descricao]
                    : null,
            ] : null),
            'rede_loja' => $this->whenLoaded('redeLoja', fn () => $this->redeLoja
                ? ['id' => $this->redeLoja->uuid, 'descricao' => $this->redeLoja->descricao]
                : null),
            'status' => $this->status,
            'prazo' => $this->prazo?->toDateString(),
            // Plano atrasado = prazo do próprio plano vencido OU alguma etapa atrasada — mesma
            // regra do filtro ?atrasados=1 do index.
            'atrasado' => $prazoVencido || $etapaAtrasada,
            'etapas_resumo' => $etapas ? [
                'total' => $etapas->count(),
                'feitas' => $etapas->filter(fn (PlanoAcaoEtapa $e) => $e->status->finalizada())->count(),
                'atrasadas' => $etapas->filter(fn (PlanoAcaoEtapa $e) => $e->atrasada())->count(),
                'bloqueadas' => $etapas->filter(fn (PlanoAcaoEtapa $e) => $e->status === StatusEtapaPlanoAcao::BLOQUEADA)->count(),
            ] : null,
            // Primeira etapa ainda não finalizada — o "onde está parado" da listagem.
            'etapa_atual' => $etapas ? (($atual = $etapas->first(fn (PlanoAcaoEtapa $e) => ! $e->status->finalizada()))
                ? new PlanoAcaoEtapaResource($atual)
                : null) : null,
            'etapas' => $this->whenLoaded('etapas', fn () => PlanoAcaoEtapaResource::collection($this->etapas)),
            'historico' => $this->whenLoaded('historicos', fn () => $this->historicos->map(fn ($h) => [
                'id' => $h->uuid,
                'acao' => $h->acao,
                'etapa_id' => $h->etapa?->uuid,
                'status_anterior' => $h->status_anterior,
                'status_novo' => $h->status_novo,
                'motivo' => $h->motivo,
                'descricao' => $h->descricao,
                'usuario' => $h->usuario ? ['id' => $h->usuario->uuid, 'nome' => $h->usuario->nome] : null,
                'created_at' => $h->created_at,
            ])),
            'criado_por' => $this->whenLoaded('criadoPor', fn () => ['id' => $this->criadoPor->uuid, 'nome' => $this->criadoPor->nome]),
            'concluido_em' => $this->concluido_em,
            'concluido_por' => $this->whenLoaded(
                'concluidoPor',
                fn () => $this->concluidoPor ? ['id' => $this->concluidoPor->uuid, 'nome' => $this->concluidoPor->nome] : null,
            ),
            'cancelado_em' => $this->cancelado_em,
            'cancelado_por' => $this->whenLoaded(
                'canceladoPor',
                fn () => $this->canceladoPor ? ['id' => $this->canceladoPor->uuid, 'nome' => $this->canceladoPor->nome] : null,
            ),
            'motivo_cancelamento' => $this->motivo_cancelamento,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
