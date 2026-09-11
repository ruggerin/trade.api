<?php

namespace App\Http\Requests\CentroCusto;

use App\Enums\CategoriaCentroCustoItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCentroCustoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:centros_custo.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:255'],
            'carga_horaria_semanal' => ['required', 'numeric', 'min:0.01', 'max:168'],
            // Lista completa dos itens de custo deste centro — sempre substituída inteira a
            // cada salvar (ver CentroCustoController::sincronizarItens), não incrementalmente.
            'itens' => ['nullable', 'array'],
            'itens.*.categoria' => ['required', Rule::in(array_column(CategoriaCentroCustoItem::cases(), 'value'))],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.valor_mensal' => ['required', 'numeric', 'min:0'],
        ];
    }
}
