<?php

namespace App\Http\Requests\OrdemServico;

use Illuminate\Foundation\Http\FormRequest;

class ReagendarOrdemServicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership checado no controller (só o dono da OS reagenda) — ver
        // OrdemServicoController::autorizarPropria.
        return true;
    }

    public function rules(): array
    {
        return [
            'prazo_inicio' => ['required', 'date'],
            'prazo_fim' => ['required', 'date', 'after_or_equal:prazo_inicio'],
        ];
    }
}
