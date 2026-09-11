<?php

namespace App\Http\Requests\Visita;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Intervenção administrativa — ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md. A permissão
 * (visitas.intervir) é checada pelo middleware da rota. A ordem entrada <= saída <= agora é
 * conferida no controller (pode combinar valor novo com valor atual da visita).
 */
class CorrigirHorariosVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'inicio_data' => ['nullable', 'date'],
            'fim_data' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('inicio_data') && ! $this->filled('fim_data')) {
                $validator->errors()->add('inicio_data', 'Informe ao menos um horário para corrigir.');
            }
        });
    }
}
