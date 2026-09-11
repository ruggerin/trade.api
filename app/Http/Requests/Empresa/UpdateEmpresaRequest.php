<?php

namespace App\Http\Requests\Empresa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Auto-edição da própria empresa pelo ADMIN — só razão social/nome fantasia. Plano e limites
 * ficam fora de propósito (sem billing nesta fase, ver docs/02-API-BACKEND.md) — só o
 * SUPERADMIN troca isso, via UpdateEmpresaSuperadminRequest.
 */
class UpdateEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type:ADMIN já validado pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'razao_social' => ['sometimes', 'string', 'max:255'],
            'nome_fantasia' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
