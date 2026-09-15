<?php

namespace App\Http\Requests\TipoRegistro;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoCampoRegistro;
use App\Support\IconeTipoRegistro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTipoRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('icone')) {
            $this->merge(['icone' => IconeTipoRegistro::normalizar($this->input('icone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'icone' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
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
            // Campo condicional — ver StoreTipoRegistroRequest.
            'campos.*.depende_de_chave' => ['nullable', 'string', 'max:50'],
            'campos.*.depende_de_valor' => ['required_with:campos.*.depende_de_chave', 'nullable', 'string'],
            // Campo SORTIMENTO — ver StoreTipoRegistroRequest.
            'campos.*.sortimento_origem' => [
                'required_if:campos.*.tipo_campo,SORTIMENTO', 'nullable',
                Rule::in(array_column(SortimentoOrigemCampo::cases(), 'value')),
            ],
            'campos.*.sortimento_tipo_vinculo' => [
                'required_if:campos.*.sortimento_origem,DINAMICO', 'nullable',
                Rule::in(['SECAO', 'DEPARTAMENTO', 'MARCA']),
            ],
            'campos.*.sortimento_secao_uuid' => [
                'required_if:campos.*.sortimento_tipo_vinculo,SECAO', 'nullable', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'campos.*.sortimento_departamento_uuid' => [
                'required_if:campos.*.sortimento_tipo_vinculo,DEPARTAMENTO', 'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'campos.*.sortimento_marca_uuid' => [
                'required_if:campos.*.sortimento_tipo_vinculo,MARCA', 'nullable', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'campos.*.sortimento_produtos_uuids' => ['required_if:campos.*.sortimento_origem,FIXO', 'array', 'min:1'],
            'campos.*.sortimento_produtos_uuids.*' => [
                'string', Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'campos.*.confirmar_ruptura_ausentes' => ['nullable', 'boolean'],
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
            'eh_alerta' => ['sometimes', 'boolean'],
            'usa_pontuacao' => ['sometimes', 'boolean'],
            'disponivel_registro_livre' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'campos.*.chave.regex' => 'A chave deve conter apenas letras minúsculas, números e underscore (ex.: quantidade).',
            'campos.*.opcoes.required_if' => 'Informe ao menos uma opção pra um campo de múltipla escolha.',
            'icone.regex' => 'Código de ícone inválido — use o slug do Material Design Icons (ex.: camera, alert, arrow-right).',
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

                $this->validarCondicional($validator);
            }

            if ($this->has('excecoes_granularidade')) {
                $secoesExcecao = collect($this->input('excecoes_granularidade', []))->pluck('secao_uuid')->filter();
                if ($secoesExcecao->count() !== $secoesExcecao->unique()->count()) {
                    $validator->errors()->add('excecoes_granularidade', 'Cada seção só pode ter uma exceção de granularidade.');
                }
            }
        });
    }

    /** Ver StoreTipoRegistroRequest::validarCondicional (mesma regra). */
    protected function validarCondicional(Validator $validator): void
    {
        $campos = array_values($this->input('campos', []));
        $indicePorChave = [];
        foreach ($campos as $indice => $campo) {
            if (! empty($campo['chave'])) {
                $indicePorChave[$campo['chave']] = $indice;
            }
        }

        foreach ($campos as $indice => $campo) {
            $dependeDeChave = $campo['depende_de_chave'] ?? null;
            if ($dependeDeChave === null) {
                continue;
            }

            if (! array_key_exists($dependeDeChave, $indicePorChave)) {
                $validator->errors()->add("campos.{$indice}.depende_de_chave", 'Precisa referenciar a chave de outro campo deste mesmo formulário.');
                continue;
            }

            if ($indicePorChave[$dependeDeChave] >= $indice) {
                $validator->errors()->add("campos.{$indice}.depende_de_chave", 'Só pode depender de um campo anterior na ordem do formulário.');
            }
        }
    }
}
