<?php

namespace App\Http\Requests\SecaoAuditoria;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecaoAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'departamento_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
