<?php

namespace App\Http\Requests\CampanhaItem;

use App\Enums\TipoItemCampanha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O discriminador tipo_item decide qual dos 4 uuids é aceito — required_if/prohibited_unless
 * garante que só o campo certo venha preenchido. Ver regra de negócio 2, docs/02-API-BACKEND.md.
 */
class StoreCampanhaItemRequest extends FormRequest
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

            // `ativo=true` em todos os 4 — mesma correção de StoreSortimentoPontoVendaRequest
            // (ver auditoria de 2026-09-10): sem isso dava pra vincular um produto ainda
            // PENDENTE de aprovação ou qualquer entidade já desativada/rejeitada à campanha.
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
