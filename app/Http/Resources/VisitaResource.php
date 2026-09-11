<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Visita
 */
class VisitaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ponto_venda' => $this->whenLoaded('pontoVenda', fn () => [
                'id' => $this->pontoVenda->uuid,
                'razao_social' => $this->pontoVenda->razao_social,
                'fantasia' => $this->pontoVenda->fantasia,
            ]),
            'usuario' => $this->whenLoaded('usuario', fn () => [
                'id' => $this->usuario->uuid,
                'nome' => $this->usuario->nome,
            ]),
            // Só presente quando havia exatamente uma campanha ativa/vigente da empresa no
            // momento do check-in — ver VisitaController::resolverCampanhaUnica.
            'campanha' => $this->whenLoaded(
                'campanha',
                fn () => $this->campanha ? [
                    'id' => $this->campanha->uuid,
                    'descricao' => $this->campanha->descricao,
                ] : null,
            ),
            // Presente só quando a visita nasceu a partir de uma OrdemServico direcionada (ver
            // VisitaController::store) — ausente/null pra visita espontânea, que continua sendo
            // o caso comum. Ver docs/07-ORDEM-DE-SERVICO.md.
            'ordem_servico' => $this->whenLoaded(
                'ordemServico',
                fn () => $this->ordemServico ? ['id' => $this->ordemServico->uuid] : null,
            ),
            'status' => $this->status,
            'inicio_data' => $this->inicio_data,
            'inicio_latitude' => $this->inicio_latitude,
            'inicio_longitude' => $this->inicio_longitude,
            'inicio_distancia_metros' => $this->inicio_distancia_metros,
            'fim_data' => $this->fim_data,
            'fim_latitude' => $this->fim_latitude,
            'fim_longitude' => $this->fim_longitude,
            'fim_distancia_metros' => $this->fim_distancia_metros,
            // PROMOTOR (checkout normal pelo app), ADMIN (forçado por um gestor, sem GPS) ou null
            // (visita ainda ABERTA) — ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
            'checkout_tipo' => $this->checkout_tipo,
            // Só presente quando carregado (GET /visitas/{uuid}) — log de cancelamento / checkout
            // forçado / correção de horário feito por um gestor.
            'intervencoes' => VisitaIntervencaoResource::collection($this->whenLoaded('intervencoes')),
            'registros' => VisitaRegistroResource::collection($this->whenLoaded('registros')),
            // Só presente quando a query usa withCount('registros') (ver VisitaController::index)
            // — pro card de histórico mostrar a contagem sem precisar carregar a coleção inteira.
            'registros_count' => $this->whenCounted('registros'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
