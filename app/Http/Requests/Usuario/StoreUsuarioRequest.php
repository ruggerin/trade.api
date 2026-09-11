<?php

namespace App\Http\Requests\Usuario;

use App\Enums\UserType;
use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type já validado pelo middleware 'permissao:usuarios.gerenciar' na rota.
        return true;
    }

    public function rules(): array
    {
        $isSuperadmin = $this->user()->user_type === UserType::SUPERADMIN;

        // Empresa alvo pra resolver o perfil_uuid contra a empresa certa: a própria (ADMIN/
        // GESTOR) ou a escolhida no formulário (SUPERADMIN, ver empresa_uuid abaixo) — SUPERADMIN
        // não tem empresa_id próprio (null), então sem isso o Rule::exists nunca bateria.
        $empresaIdAlvo = $isSuperadmin
            ? Empresa::where('uuid', $this->input('empresa_uuid'))->value('id')
            : $this->user()->empresa_id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:usuarios,email'],
            'senha' => ['required', 'string', 'min:8'],
            // SUPERADMIN nunca é atribuível por aqui — só via comando artisan
            // usuario:criar-superadmin, fora da API pública.
            'user_type' => ['required', Rule::in([
                UserType::ADMIN->value,
                UserType::GESTOR->value,
                UserType::PROMOTOR->value,
            ])],
            // Só SUPERADMIN manda isso (pra escolher em qual empresa criar) — obrigatório pra
            // ele, proibido pros outros (ver withValidator: ADMIN/GESTOR já são presos à própria
            // empresa via BelongsToEmpresa, não faz sentido eles escolherem outra).
            'empresa_uuid' => [
                Rule::requiredIf($isSuperadmin), 'nullable', 'string',
                Rule::exists('empresas', 'uuid'),
            ],
            // GESTOR (permissões de escrita no admin) ou PROMOTOR (visibilidade no mobile, ex.
            // "ver todos os PDVs") — ver docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md. ADMIN sempre
            // tem acesso total, SUPERADMIN não usa perfil.
            'perfil_uuid' => [
                'nullable', 'string',
                Rule::exists('perfis', 'uuid')->where('empresa_id', $empresaIdAlvo),
            ],
            // Só faz sentido pra PROMOTOR — ver docs/08-CENTRO-DE-CUSTO.md.
            'centro_custo_uuid' => [
                'nullable', 'string',
                Rule::exists('centros_custo', 'uuid')->where('empresa_id', $empresaIdAlvo),
            ],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('perfil_uuid') && ! in_array($this->input('user_type'), [UserType::GESTOR->value, UserType::PROMOTOR->value], true)) {
                $validator->errors()->add('perfil_uuid', 'Perfil só pode ser atribuído a usuários Gestor ou Promotor.');
            }

            if ($this->filled('centro_custo_uuid') && $this->input('user_type') !== UserType::PROMOTOR->value) {
                $validator->errors()->add('centro_custo_uuid', 'Centro de custo só pode ser atribuído a usuários PROMOTOR.');
            }

            if ($this->filled('empresa_uuid') && $this->user()->user_type !== UserType::SUPERADMIN) {
                $validator->errors()->add('empresa_uuid', 'Só o suporte pode escolher a empresa ao criar um usuário.');
            }
        });
    }
}
