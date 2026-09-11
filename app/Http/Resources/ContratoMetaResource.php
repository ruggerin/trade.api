<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ContratoMeta
 */
class ContratoMetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'marca' => $this->whenLoaded(
                'marca',
                fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null,
            ),
            'descricao' => $this->descricao,
            'valor_investimento' => $this->valor_investimento,
            'meta_valor' => $this->meta_valor,
            'periodo_inicio' => $this->periodo_inicio,
            'periodo_fim' => $this->periodo_fim,
            'fonte_pagamento' => $this->fonte_pagamento,
            'percentual_industria' => $this->percentual_industria,
            'resultado_apurado' => $this->resultado_apurado,
            'apurado_em' => $this->apurado_em,
            'apurado_por' => $this->whenLoaded(
                'apuradoPor',
                fn () => $this->apuradoPor ? ['id' => $this->apuradoPor->uuid, 'nome' => $this->apuradoPor->nome] : null,
            ),
            // Nunca persistido — sempre computado a partir dos campos negociados + resultado
            // apurado. Ver App\Models\ContratoMeta::resumo e docs/09-CONTRATO-METAS.md §4.
            'resumo' => $this->resumo(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
