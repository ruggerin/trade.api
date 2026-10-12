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
            // Metadados do aparelho pro histórico e a tela de licenças do ADMIN (docs/67 §4.2).
            'dispositivo_plataforma' => ['nullable', 'string', 'in:android,ios'],
            'dispositivo_versao_so' => ['nullable', 'string', 'max:50'],
            // Fase 3 (docs/67): chave pública P-256 do aparelho (ponto não comprimido, base64), a
            // atestação amarrada a ela e os sinais de root/emulador. Ver AtestacaoAparelho.
            'chave_publica' => ['nullable', 'string', 'max:200'],
            'atestacao' => ['nullable', 'array'],
            'atestacao.desafio' => ['nullable', 'string', 'max:64'],
            'atestacao.plataforma' => ['nullable', 'string', 'in:android,ios'],
            'atestacao.token' => ['nullable', 'string', 'max:20000'],
            'atestacao.key_id' => ['nullable', 'string', 'max:100'],
            'atestacao.objeto' => ['nullable', 'string', 'max:20000'],
            'atestacao.assercao' => ['nullable', 'string', 'max:5000'],
            'integridade' => ['nullable', 'array'],
            'integridade.root' => ['nullable', 'boolean'],
            'integridade.emulador' => ['nullable', 'boolean'],
        ];
    }
}
