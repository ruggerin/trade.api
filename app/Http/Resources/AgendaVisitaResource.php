<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AgendaVisita
 */
class AgendaVisitaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ponto_venda' => $this->whenLoaded(
                'pontoVenda',
                fn () => ['id' => $this->pontoVenda->uuid, 'fantasia' => $this->pontoVenda->fantasia],
            ),
            'usuario' => $this->whenLoaded(
                'usuario',
                fn () => ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome],
            ),
            'tipo_visita' => $this->whenLoaded(
                'tipoVisita',
                fn () => $this->tipoVisita ? new TipoVisitaResource($this->tipoVisita) : null,
            ),
            'objetivo_visita' => $this->whenLoaded(
                'objetivoVisita',
                fn () => $this->objetivoVisita ? new ObjetivoVisitaResource($this->objetivoVisita) : null,
            ),
            'prioridade' => $this->prioridade,
            'recorrencia' => $this->recorrencia,
            'dia_semana' => $this->dia_semana,
            'data' => $this->data?->toDateString(),
            // Postgres devolve TIME com segundos ("09:00:00") — corta pra "HH:mm", mesmo formato
            // aceito na validação de entrada (date_format:H:i).
            'horario_previsto' => $this->horario_previsto ? substr($this->horario_previsto, 0, 5) : null,
            'obrigatoria' => $this->obrigatoria,
            'ativo' => $this->ativo,
            'observacao' => $this->observacao,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
