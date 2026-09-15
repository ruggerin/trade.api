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
            // Só preenchido quando tipo_campo = SORTIMENTO — ver App\Support\ResolverSortimentoCampo
            // e decisão 3 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
            'sortimento_origem' => $this->sortimento_origem?->value,
            'sortimento_tipo_vinculo' => $this->sortimento_tipo_vinculo?->value,
            'sortimento_secao' => $this->whenLoaded('sortimentoSecao', fn () => $this->sortimentoSecao ? [
                'id' => $this->sortimentoSecao->uuid, 'descricao' => $this->sortimentoSecao->descricao,
            ] : null),
            'sortimento_departamento' => $this->whenLoaded('sortimentoDepartamento', fn () => $this->sortimentoDepartamento ? [
                'id' => $this->sortimentoDepartamento->uuid, 'descricao' => $this->sortimentoDepartamento->descricao,
            ] : null),
            'sortimento_marca' => $this->whenLoaded('sortimentoMarca', fn () => $this->sortimentoMarca ? [
                'id' => $this->sortimentoMarca->uuid, 'descricao' => $this->sortimentoMarca->descricao,
            ] : null),
            'sortimento_produtos' => $this->whenLoaded('produtosFixos', fn () => $this->produtosFixos->map(fn ($produto) => [
                'id' => $produto->uuid, 'descricao' => $produto->descricao,
            ])),
            'confirmar_ruptura_ausentes' => $this->confirmar_ruptura_ausentes,
        ];
    }
}
