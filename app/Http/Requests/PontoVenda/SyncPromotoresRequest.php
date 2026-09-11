<?php

namespace App\Http\Requests\PontoVenda;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sincroniza (substitui) o conjunto de promotores que atendem uma loja — ver
 * docs/02-API-BACKEND.md, regra de negócio 6.
 */
class SyncPromotoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        // permissao:pontos_venda.gerenciar já validado pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Models\PontoVenda $pontoVendaAlvo */
        $pontoVendaAlvo = $this->route('pontoVenda');

        return [
            'usuarios_uuids' => ['present', 'array'],
            'usuarios_uuids.*' => [
                'string',
                Rule::exists('usuarios', 'uuid')
                    ->where('empresa_id', $pontoVendaAlvo->empresa_id)
                    ->where('user_type', UserType::PROMOTOR->value)
                    ->where('ativo', true),
            ],
        ];
    }
}
