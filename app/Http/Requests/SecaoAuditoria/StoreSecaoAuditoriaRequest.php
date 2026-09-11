<?php

namespace App\Http\Requests\SecaoAuditoria;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecaoAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:255'],
            // Nullable de propósito: nunca usar um id "0" curinga como no sistema antigo — ver
            // docs/01-MODELO-DE-DADOS.md.
            'departamento_uuid' => [
                'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
        ];
    }
}
