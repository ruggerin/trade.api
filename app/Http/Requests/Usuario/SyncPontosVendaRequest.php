<?php

namespace App\Http\Requests\Usuario;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sincroniza (substitui) o conjunto de lojas que um promotor atende — ver
 * docs/02-API-BACKEND.md, regra de negócio 6.
 */
class SyncPontosVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // permissao:usuarios.gerenciar já validado pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Models\Usuario $usuarioAlvo */
        $usuarioAlvo = $this->route('usuario');

        return [
            'pontos_venda_uuids' => ['present', 'array'],
            'pontos_venda_uuids.*' => [
                'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $usuarioAlvo->empresa_id),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var \App\Models\Usuario $usuarioAlvo */
            $usuarioAlvo = $this->route('usuario');

            if ($usuarioAlvo->user_type !== UserType::PROMOTOR) {
                $validator->errors()->add('pontos_venda_uuids', 'Vínculo de ponto de venda só se aplica a promotores.');
            }
        });
    }
}
