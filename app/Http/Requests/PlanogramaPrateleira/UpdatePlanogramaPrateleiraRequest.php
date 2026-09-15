<?php

namespace App\Http\Requests\PlanogramaPrateleira;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanogramaPrateleiraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantidade_blocos' => ['sometimes', 'integer', 'min:1', 'max:200'],
            // Confirma a remoção de blocos que ultrapassariam a nova quantidade_blocos (ver
            // PlanogramaPrateleiraController::update) — sem isso, reduzir com bloco além do
            // limite novo retorna 422 listando o que seria removido, em vez de aplicar direto.
            // Ver docs/22-PLANOGRAMA.md, decisão 2.
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
