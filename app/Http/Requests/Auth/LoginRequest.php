<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'senha' => ['required', 'string'],
            // Obrigatório só para PROMOTOR (checado no controller — antes de autenticar não
            // dá pra saber o user_type do usuário). Ver AuthController::login.
            'dispositivo_identificador' => ['nullable', 'string', 'max:255'],
            'dispositivo_nome' => ['nullable', 'string', 'max:255'],
        ];
    }
}
