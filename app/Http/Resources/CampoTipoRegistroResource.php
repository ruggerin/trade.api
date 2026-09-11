<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CampoTipoRegistro
 */
class CampoTipoRegistroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'chave' => $this->chave,
            'rotulo' => $this->rotulo,
            'tipo_campo' => $this->tipo_campo,
            'opcoes' => $this->opcoes,
            'obrigatorio' => $this->obrigatorio,
            'ordem' => $this->ordem,
        ];
    }
}
