<?php

namespace App\Http\Requests\Fatura;

use App\Enums\StatusFatura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'valor' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'referencia' => ['sometimes', 'required', 'date'],
            'vencimento' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', Rule::enum(StatusFatura::class)],
            'pago_em' => ['sometimes', 'nullable', 'date'],
            'observacao' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
