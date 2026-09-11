<?php

namespace App\Http\Requests\ContratoMeta;

use App\Enums\FontePagamentoMeta;
use App\Models\Contrato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContratoMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:contratos.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        /** @var Contrato $contratoAlvo */
        $contratoAlvo = $this->route('contrato');

        return [
            // NULL = meta geral do PDV, sem recorte por marca — ver docs/09-CONTRATO-METAS.md §3.
            'marca_uuid' => [
                'nullable', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $contratoAlvo->empresa_id),
            ],
            'descricao' => ['nullable', 'string', 'max:255'],
            'valor_investimento' => ['required', 'numeric', 'min:0'],
            'meta_valor' => ['required', 'numeric', 'min:0'],
            'periodo_inicio' => ['required', 'date'],
            'periodo_fim' => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'fonte_pagamento' => ['required', Rule::enum(FontePagamentoMeta::class)],
            // Só usado (e só exigido) quando fonte_pagamento = COMPARTILHADO — ver
            // docs/09-CONTRATO-METAS.md §3.
            'percentual_industria' => [
                'required_if:fonte_pagamento,COMPARTILHADO',
                'prohibited_unless:fonte_pagamento,COMPARTILHADO',
                'numeric', 'between:0,100',
            ],
        ];
    }
}
