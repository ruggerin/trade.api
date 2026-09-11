<?php

namespace App\Http\Requests\EmpresaSuperadmin;

use App\Enums\PlanoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmpresaSuperadminRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type já validado pelo middleware 'user_type:SUPERADMIN' na rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20', 'unique:empresas,cnpj'],
            'plano' => ['required', Rule::enum(PlanoEmpresa::class)],
            'limite_usuarios' => ['nullable', 'integer', 'min:1'],
            'limite_pontos_venda' => ['nullable', 'integer', 'min:1'],
            // Cobrança por licença de dispositivo — conta usuários PROMOTOR ativos (cada um
            // trava 1 dispositivo por vez, ver AuthController::login). null = sem limite.
            'limite_licencas' => ['nullable', 'integer', 'min:1'],
            // Cria junto o primeiro ADMIN da empresa — sem isso não existe forma de logar
            // nela, o signup público cria uma empresa nova a cada vez, não anexa a uma
            // existente. Mesmo padrão de campos do EmpresaController::signup.
            'admin_nome' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'unique:usuarios,email'],
            'admin_senha' => ['required', 'string', 'min:8'],
        ];
    }
}
