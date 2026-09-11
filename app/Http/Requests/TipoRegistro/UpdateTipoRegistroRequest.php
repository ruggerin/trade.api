<?php

namespace App\Http\Requests\TipoRegistro;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Enums\TipoCampoRegistro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTipoRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'exige_foto' => ['sometimes', 'boolean'],
            'permite_vincular_catalogo' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
            'acao_obrigatoria' => ['sometimes', 'boolean'],
            'escopo_acao' => [
                'required_if:acao_obrigatoria,true',
                'nullable',
                Rule::in(array_column(EscopoAcaoTipoRegistro::cases(), 'value')),
            ],
            'campanha_auditoria_uuid' => [
                'required_if:escopo_acao,CAMPANHA',
                'nullable',
                'string',
                Rule::exists('campanhas_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            // Quando enviado, substitui a lista inteira de campos (ver
            // TipoRegistroController::update) — omitir a chave inteira mantém os campos atuais.
            'campos' => ['sometimes', 'array'],
            'campos.*.chave' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'campos.*.rotulo' => ['required', 'string', 'max:255'],
            'campos.*.tipo_campo' => ['required', Rule::in(array_column(TipoCampoRegistro::cases(), 'value'))],
            'campos.*.opcoes' => ['required_if:campos.*.tipo_campo,MULTIPLA_ESCOLHA', 'array', 'min:1'],
            'campos.*.opcoes.*' => ['string', 'max:255'],
            'campos.*.obrigatorio' => ['nullable', 'boolean'],
            'granularidade_padrao' => ['nullable', Rule::in(array_column(GranularidadeResposta::cases(), 'value'))],
            // Quando enviado, substitui a lista inteira de exceções (ver
            // TipoRegistroController::sincronizarExcecoesGranularidade).
            'excecoes_granularidade' => ['sometimes', 'array'],
            'excecoes_granularidade.*.secao_uuid' => [
                'required', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'excecoes_granularidade.*.granularidade' => [
                'required', Rule::in(array_column(GranularidadeResposta::cases(), 'value')),
            ],
            'eh_ruptura' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'campos.*.chave.regex' => 'A chave deve conter apenas letras minúsculas, números e underscore (ex.: quantidade).',
            'campos.*.opcoes.required_if' => 'Informe ao menos uma opção pra um campo de múltipla escolha.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('campos')) {
                $chaves = collect($this->input('campos', []))->pluck('chave')->filter();
                if ($chaves->count() !== $chaves->unique()->count()) {
                    $validator->errors()->add('campos', 'As chaves dos campos precisam ser únicas dentro do mesmo tipo.');
                }
            }

            if ($this->has('excecoes_granularidade')) {
                $secoesExcecao = collect($this->input('excecoes_granularidade', []))->pluck('secao_uuid')->filter();
                if ($secoesExcecao->count() !== $secoesExcecao->unique()->count()) {
                    $validator->errors()->add('excecoes_granularidade', 'Cada seção só pode ter uma exceção de granularidade.');
                }
            }
        });
    }
}
