<?php

namespace App\Http\Requests\ContratoMeta;

use App\Enums\FontePagamentoMeta;
use App\Models\ContratoMeta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mesmo endpoint serve tanto pra editar os dados negociados quanto pra "lançar o resultado"
 * (campo `resultado_apurado`) — não é uma ação separada, ver docs/09-CONTRATO-METAS.md §5.
 */
class UpdateContratoMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var ContratoMeta $metaAlvo */
        $metaAlvo = $this->route('meta');

        return [
            'marca_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $metaAlvo->contrato->empresa_id),
            ],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:255'],
            'valor_investimento' => ['sometimes', 'required', 'numeric', 'min:0'],
            'meta_valor' => ['sometimes', 'required', 'numeric', 'min:0'],
            'periodo_inicio' => ['sometimes', 'required', 'date'],
            'periodo_fim' => ['sometimes', 'required', 'date', 'after_or_equal:periodo_inicio'],
            'fonte_pagamento' => ['sometimes', 'required', Rule::enum(FontePagamentoMeta::class)],
            'percentual_industria' => [
                'required_if:fonte_pagamento,COMPARTILHADO',
                'prohibited_unless:fonte_pagamento,COMPARTILHADO',
                'nullable', 'numeric', 'between:0,100',
            ],
            // Lançamento do resultado — nullable pra permitir desfazer um lançamento errado.
            'resultado_apurado' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
