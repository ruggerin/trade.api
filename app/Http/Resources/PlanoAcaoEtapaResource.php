<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PlanoAcaoEtapa
 */
class PlanoAcaoEtapaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ordem' => $this->ordem,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'prazo' => $this->prazo?->toDateString(),
            'status' => $this->status,
            // Calculado, nunca gravado — ver PlanoAcaoEtapa::atrasada e docs/37 §4.5.
            'atrasada' => $this->atrasada(),
            'responsavel' => $this->whenLoaded(
                'responsavel',
                fn () => $this->responsavel ? ['id' => $this->responsavel->uuid, 'nome' => $this->responsavel->nome] : null,
            ),
            'responsavel_externo_nome' => $this->responsavel_externo_nome,
            'responsavel_externo_contato' => $this->responsavel_externo_contato,
            'evidencia_obrigatoria' => $this->evidencia_obrigatoria,
            'evidencia_texto' => $this->evidencia_texto,
            // Disco privado, servido autenticado — mesmo padrão de ContratoController::arquivo.
            'evidencia_arquivo_url' => $this->evidencia_arquivo_path && $this->relationLoaded('planoAcao')
                ? url("/api/planos-acao/{$this->planoAcao->uuid}/etapas/{$this->uuid}/evidencia")
                : null,
            'motivo' => $this->motivo,
            'feita_em' => $this->feita_em,
            'feita_por' => $this->whenLoaded(
                'feitaPor',
                fn () => $this->feitaPor ? ['id' => $this->feitaPor->uuid, 'nome' => $this->feitaPor->nome] : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
