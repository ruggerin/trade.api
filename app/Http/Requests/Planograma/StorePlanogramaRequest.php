<?php

namespace App\Http\Requests\Planograma;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanogramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:255'],
        ];
    }
}
