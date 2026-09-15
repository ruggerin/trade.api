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
            // Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — expõe a
            // `chave` do campo pai, não o id interno, mesmo contrato que o Store/UpdateRequest
            // aceita de volta (ver TipoRegistroController::sincronizarCampos).
            'depende_de_chave' => $this->whenLoaded('dependeDe', fn () => $this->dependeDe?->chave),
            'depende_de_valor' => $this->depende_de_valor,
        ];
    }
}
