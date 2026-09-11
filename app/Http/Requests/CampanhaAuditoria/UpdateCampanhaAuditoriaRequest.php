<?php

namespace App\Http\Requests\CampanhaAuditoria;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampanhaAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:155'],
            'observacao' => ['sometimes', 'nullable', 'string'],
            'layout' => ['sometimes', 'nullable', 'string', 'max:155'],
            'vigencia_inicio' => ['sometimes', 'required', 'date'],
            'vigencia_fim' => ['sometimes', 'required', 'date', 'after_or_equal:vigencia_inicio'],
            'restricao' => ['sometimes', 'nullable', 'string'],
            'exclusividade' => ['sometimes', 'nullable', 'string'],
            'frequencia_dias' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'execucao_recorrente' => ['sometimes', 'boolean'],
            'possui_restricao' => ['sometimes', 'boolean'],
            'possui_exclusividade' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
