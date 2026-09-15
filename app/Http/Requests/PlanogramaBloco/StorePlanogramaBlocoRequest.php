<?php

namespace App\Http\Requests\PlanogramaBloco;

use App\Models\PlanogramaPrateleira;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cria 1..N blocos numa tacada só (mesmo produto em todos) — cobre tanto o arrasto de uma
 * célula só (array com 1 item) quanto "aplicar aos selecionados" no editor (array com N),
 * ver docs/22-PLANOGRAMA.md §4. Transacional — PlanogramaBlocoController::store garante que
 * nenhum bloco é criado se algum item da lista invalidar.
 */
class StorePlanogramaBlocoRequest extends FormRequest
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
                'required', 'string', 'uuid',
                Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $prateleira->planograma->empresa_id),
            ],
            'blocos' => ['required', 'array', 'min:1'],
            'blocos.*.posicao_inicio' => ['required', 'integer', 'min:0'],
            'blocos.*.largura' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var PlanogramaPrateleira $prateleira */
            $prateleira = $this->route('prateleira');
            $blocos = $this->input('blocos', []);

            // Faixas [inicio, fim) já ocupadas por blocos existentes desta prateleira.
            $ocupadas = $prateleira->blocos()->get(['posicao_inicio', 'largura'])
                ->map(fn ($b) => [$b->posicao_inicio, $b->posicao_inicio + $b->largura])
                ->all();

            $novasFaixas = [];
            foreach ($blocos as $indice => $bloco) {
                $inicio = $bloco['posicao_inicio'] ?? null;
                $largura = $bloco['largura'] ?? null;
                if (! is_int($inicio) || ! is_int($largura)) {
                    continue; // já reportado pela regra estática acima
                }
                $fim = $inicio + $largura;

                if ($fim > $prateleira->quantidade_blocos) {
                    $validator->errors()->add(
                        "blocos.{$indice}",
                        "A posição {$inicio} + largura {$largura} ultrapassa os {$prateleira->quantidade_blocos} blocos da prateleira.",
                    );
                    continue;
                }

                foreach ([...$ocupadas, ...$novasFaixas] as [$outroInicio, $outroFim]) {
                    if ($inicio < $outroFim && $outroInicio < $fim) {
                        $validator->errors()->add("blocos.{$indice}", 'Sobrepõe outro bloco já existente nesta prateleira.');
                        continue 2;
                    }
                }

                $novasFaixas[] = [$inicio, $fim];
            }
        });
    }
}
