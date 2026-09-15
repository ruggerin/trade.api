<?php

namespace App\Http\Requests\PlanogramaPrateleira;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanogramaPrateleiraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['nullable', 'string', 'max:255'],
            'quantidade_blocos' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
