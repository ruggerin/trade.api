<?php

namespace App\Http\Requests\Usuario;

use App\Enums\UserType;
use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('usuarios', 'email')->ignore($this->route('usuario'))],
            // Reset de senha manual pelo admin — ver docs/03-ADMIN-WEB.md.
            'senha' => ['sometimes', 'required', 'string', 'min:8'],
            'user_type' => ['sometimes', 'required', Rule::in([
                UserType::ADMIN->value,
                UserType::GESTOR->value,
                UserType::PROMOTOR->value,
            ])],
            // Validado contra a empresa do usuário ALVO da rota, não do chamador — pra
            // ADMIN/GESTOR isso sempre bate igual (só editam gente da própria empresa mesmo),
            // mas faz diferença pro SUPERADMIN, que não tem empresa própria (empresa_id null) e
            // edita usuário de qualquer empresa.
            'perfil_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('perfis', 'uuid')->where('empresa_id', $this->route('usuario')?->empresa_id),
            ],
            // Só faz sentido pra PROMOTOR — ver docs/08-CENTRO-DE-CUSTO.md.
            'centro_custo_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('centros_custo', 'uuid')->where('empresa_id', $this->route('usuario')?->empresa_id),
            ],
            'ativo' => ['sometimes', 'boolean'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Usuario $usuario */
            $usuario = $this->route('usuario');
            $userTypeEfetivo = $this->input('user_type') ?? $usuario->user_type->value;

            if ($this->filled('perfil_uuid') && ! in_array($userTypeEfetivo, [UserType::GESTOR->value, UserType::PROMOTOR->value], true)) {
                $validator->errors()->add('perfil_uuid', 'Perfil só pode ser atribuído a usuários Gestor ou Promotor.');
            }

            if ($this->filled('centro_custo_uuid') && $userTypeEfetivo !== UserType::PROMOTOR->value) {
                $validator->errors()->add('centro_custo_uuid', 'Centro de custo só pode ser atribuído a usuários PROMOTOR.');
            }
        });
    }
}
