<?php

namespace App\Http\Requests\PontoVenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePontoVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo_externo' => ['nullable', 'string', 'max:50'],
            'cnpj' => [
                'nullable', 'string', 'max:20',
                Rule::unique('pontos_venda', 'cnpj')->where('empresa_id', $this->user()->empresa_id),
            ],
            'razao_social' => ['required', 'string', 'max:255'],
            'fantasia' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'endereco' => ['required', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:10'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['required', 'string', 'max:100'],
            'cep' => ['nullable', 'string', 'max:10'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'rede_loja_uuid' => [
                'nullable', 'string',
                Rule::exists('redes_lojas', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'ramo_atividade_uuid' => [
                'nullable', 'string',
                Rule::exists('ramos_atividade', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'numero_checkouts' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
