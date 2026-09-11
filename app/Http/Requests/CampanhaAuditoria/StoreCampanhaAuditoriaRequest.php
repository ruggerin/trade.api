<?php

namespace App\Http\Requests\CampanhaAuditoria;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampanhaAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:155'],
            'observacao' => ['nullable', 'string'],
            'layout' => ['nullable', 'string', 'max:155'],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['required', 'date', 'after_or_equal:vigencia_inicio'],
            'restricao' => ['nullable', 'string'],
            'exclusividade' => ['nullable', 'string'],
            'frequencia_dias' => ['nullable', 'integer', 'min:1'],
            'execucao_recorrente' => ['nullable', 'boolean'],
            'possui_restricao' => ['nullable', 'boolean'],
            'possui_exclusividade' => ['nullable', 'boolean'],
        ];
    }
}
