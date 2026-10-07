<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RelatorioPersonalizado
 */
class RelatorioPersonalizadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'entidade' => $this->entidade,
            'definicao' => $this->definicao,
            'compartilhado' => $this->compartilhado,
            'padrao' => $this->padrao,
            'chave' => $this->chave,
            // docs/63 §1.7 — `fixado_meu` vem do controller (withExists), ausente fora da listagem.
            'fixado_empresa' => $this->fixado_empresa,
            'fixado_meu' => $this->when(isset($this->fixado_meu), fn () => (bool) $this->fixado_meu),
            'criador' => $this->whenLoaded('usuario', fn () => $this->usuario ? ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome] : null),
            // O front decide entre "Editar" e "Duplicar para editar" (padrão nunca é editável).
            'pode_editar' => $request->user() ? $this->editavelPor($request->user()) : false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
