<?php

namespace App\Http\Requests\Visita;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Intervenção administrativa — ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md. A permissão
 * (visitas.intervir) é checada pelo middleware da rota. A sanidade de `fim_data` (>= entrada,
 * <= agora) é conferida no controller, que tem a visita em mãos.
 */
class ForcarCheckoutVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'fim_data' => ['required', 'date'],
        ];
    }
}
