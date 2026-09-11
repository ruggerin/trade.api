<?php

namespace App\Http\Requests\SortimentoPontoVenda;

use App\Enums\TipoItemCampanha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usada tanto pelo admin web (`pontos_venda.gerenciar`) quanto pelo self-service do mobile —
 * mesmo desenho de StoreCampanhaItemRequest: o discriminador tipo_item decide qual dos 4 uuids
 * é aceito. Ver docs/14-SORTIMENTO-PONTO-VENDA.md §3/§4.
 */
class StoreSortimentoPontoVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'tipo_item' => ['required', Rule::enum(TipoItemCampanha::class)],

            // `ativo=true` em todos os 4 — sem isso dava pra vincular um produto ainda PENDENTE
            // de aprovação (self-service, ver docs/14-SORTIMENTO-PONTO-VENDA.md §8) ou qualquer
            // entidade já desativada/rejeitada, criando um item de sortimento "fantasma" que
            // nunca deveria ter sido possível cadastrar.
            'produto_uuid' => [
                'required_if:tipo_item,PRODUTO', 'prohibited_unless:tipo_item,PRODUTO', 'string',
                Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $empresaId)->where('ativo', true),
            ],
            'departamento_uuid' => [
                'required_if:tipo_item,DEPARTAMENTO', 'prohibited_unless:tipo_item,DEPARTAMENTO', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $empresaId)->where('ativo', true),
            ],
            'secao_uuid' => [
                'required_if:tipo_item,SECAO', 'prohibited_unless:tipo_item,SECAO', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $empresaId)->where('ativo', true),
            ],
            'marca_uuid' => [
                'required_if:tipo_item,MARCA', 'prohibited_unless:tipo_item,MARCA', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $empresaId)->where('ativo', true),
            ],
        ];
    }
}
