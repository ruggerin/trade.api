<?php

namespace App\Http\Requests\Fatura;

use App\Enums\StatusFatura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type já validado pelo middleware 'user_type:SUPERADMIN' na rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'valor' => ['required', 'numeric', 'min:0.01'],
            'referencia' => ['required', 'date'],
            'vencimento' => ['required', 'date'],
            'status' => ['sometimes', Rule::enum(StatusFatura::class)],
            'pago_em' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string'],
        ];
    }
}
