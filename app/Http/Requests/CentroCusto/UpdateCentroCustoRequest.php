<?php

namespace App\Http\Requests\CentroCusto;

use App\Enums\CategoriaCentroCustoItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCentroCustoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'carga_horaria_semanal' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:168'],
            'itens' => ['sometimes', 'nullable', 'array'],
            'itens.*.categoria' => ['required', Rule::in(array_column(CategoriaCentroCustoItem::cases(), 'value'))],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.valor_mensal' => ['required', 'numeric', 'min:0'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
