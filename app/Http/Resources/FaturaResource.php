<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Fatura
 */
class FaturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'valor' => (float) $this->valor,
            'referencia' => $this->referencia->toDateString(),
            'vencimento' => $this->vencimento->toDateString(),
            'status' => $this->status,
            'pago_em' => $this->pago_em?->toDateString(),
            'observacao' => $this->observacao,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
