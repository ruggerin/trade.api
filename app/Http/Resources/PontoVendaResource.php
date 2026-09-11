<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PontoVenda
 */
class PontoVendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'codigo_externo' => $this->codigo_externo,
            'cnpj' => $this->cnpj,
            'razao_social' => $this->razao_social,
            'fantasia' => $this->fantasia,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'endereco' => $this->endereco,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'cep' => $this->cep,
            'telefone' => $this->telefone,
            'email' => $this->email,
            // Promotores que atendem esta loja — ver App\Models\PontoVenda::promotores e
            // docs/02-API-BACKEND.md, regra de negócio 6.
            'promotores' => $this->whenLoaded(
                'promotores',
                fn () => $this->promotores->map(fn ($p) => ['id' => $p->uuid, 'nome' => $p->nome]),
            ),
            // Só vem preenchido pra quem pede como SUPERADMIN (ver PontoVendaController) — usado
            // pra mostrar a coluna/filtro "Empresa" no admin web, mesmo padrão de
            // DepartamentoAuditoriaResource.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            // Só carregado no detalhe (PontoVendaController::show) — ver
            // docs/14-SORTIMENTO-PONTO-VENDA.md §4.
            'sortimento' => $this->whenLoaded('sortimento', fn () => SortimentoPontoVendaResource::collection($this->sortimento)),
            // Ver App\Enums\EscopoAcaoTipoRegistro::CONTRATO — não expõe dado nenhum do
            // contrato em si (isso continua só no admin web), só esse booleano pro app mobile
            // saber se deve mostrar a Ação correspondente.
            'tem_contrato_ativo' => (bool) $this->tem_contrato_ativo,
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
