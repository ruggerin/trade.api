<?php

namespace App\Http\Requests\PlanogramaBloco;

use App\Models\PlanogramaBloco;
use App\Models\PlanogramaPrateleira;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlanogramaBlocoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var PlanogramaPrateleira $prateleira */
        $prateleira = $this->route('prateleira');

        return [
            'produto_auditoria_uuid' => [
                'sometimes', 'required', 'string', 'uuid',
                Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $prateleira->planograma->empresa_id),
            ],
            'posicao_inicio' => ['sometimes', 'required', 'integer', 'min:0'],
            'largura' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(['posicao_inicio', 'largura'])) {
                return;
            }

            /** @var PlanogramaPrateleira $prateleira */
            $prateleira = $this->route('prateleira');
            /** @var PlanogramaBloco $bloco */
            $bloco = $this->route('bloco');

            $inicio = $this->input('posicao_inicio', $bloco->posicao_inicio);
            $largura = $this->input('largura', $bloco->largura);
            $fim = $inicio + $largura;

            if ($fim > $prateleira->quantidade_blocos) {
                $validator->errors()->add('posicao_inicio', "Ultrapassa os {$prateleira->quantidade_blocos} blocos da prateleira.");

                return;
            }

            $sobrepoe = $prateleira->blocos()
                ->where('id', '!=', $bloco->id)
                ->get(['posicao_inicio', 'largura'])
                ->contains(fn ($outro) => $inicio < $outro->posicao_inicio + $outro->largura && $outro->posicao_inicio < $fim);

            if ($sobrepoe) {
                $validator->errors()->add('posicao_inicio', 'Sobrepõe outro bloco já existente nesta prateleira.');
            }
        });
    }
}
