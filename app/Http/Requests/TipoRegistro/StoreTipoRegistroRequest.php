<?php

namespace App\Http\Requests\TipoRegistro;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Enums\TipoCampoRegistro;
use App\Support\IconeTipoRegistro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTipoRegistroRequest extends FormRequest
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
            'descricao' => ['required', 'string', 'max:255'],
            // Slug do Material Design Icons, já normalizado em prepareForValidation() (sem
            // prefixo "mdi-", minúsculo) — ver App\Support\IconeTipoRegistro.
            'icone' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'exige_foto' => ['nullable', 'boolean'],
            'permite_vincular_catalogo' => ['nullable', 'boolean'],
            // Ação obrigatória (ver App\Enums\EscopoAcaoTipoRegistro) — vira pendência na aba
            // Ações da visita em vez de só uma opção do Registro geral.
            'acao_obrigatoria' => ['nullable', 'boolean'],
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
            // Lista completa dos campos customizados deste tipo — sempre substituída inteira a
            // cada salvar (ver TipoRegistroController), não incrementalmente.
            'campos' => ['nullable', 'array'],
            'campos.*.chave' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'campos.*.rotulo' => ['required', 'string', 'max:255'],
            'campos.*.tipo_campo' => ['required', Rule::in(array_column(TipoCampoRegistro::cases(), 'value'))],
            'campos.*.opcoes' => ['required_if:campos.*.tipo_campo,MULTIPLA_ESCOLHA', 'array', 'min:1'],
            'campos.*.opcoes.*' => ['string', 'max:255'],
            'campos.*.obrigatorio' => ['nullable', 'boolean'],
            // Granularidade padrão da pergunta (LINHA/PRODUTO) — ver
            // App\Support\GranularidadeChecklist. `null` = sem regra, comportamento livre atual.
            'granularidade_padrao' => ['nullable', Rule::in(array_column(GranularidadeResposta::cases(), 'value'))],
            // Lista completa de exceções por seção — mesma semântica de "campos": sempre
            // substituída inteira (ver TipoRegistroController::sincronizarExcecoesGranularidade).
            'excecoes_granularidade' => ['nullable', 'array'],
            'excecoes_granularidade.*.secao_uuid' => [
                'required', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'excecoes_granularidade.*.granularidade' => [
                'required', Rule::in(array_column(GranularidadeResposta::cases(), 'value')),
            ],
            // Marca a coluna "Ruptura" da grade de coleta — ver
            // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9.
            'eh_ruptura' => ['nullable', 'boolean'],
            // Dispara evento de alerta no Painel de Atividades — ver
            // docs/17-PAINEL-ATIVIDADES.md.
            'eh_alerta' => ['nullable', 'boolean'],
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
            $chaves = collect($this->input('campos', []))->pluck('chave')->filter();
            if ($chaves->count() !== $chaves->unique()->count()) {
                $validator->errors()->add('campos', 'As chaves dos campos precisam ser únicas dentro do mesmo tipo.');
            }

            $secoesExcecao = collect($this->input('excecoes_granularidade', []))->pluck('secao_uuid')->filter();
            if ($secoesExcecao->count() !== $secoesExcecao->unique()->count()) {
                $validator->errors()->add('excecoes_granularidade', 'Cada seção só pode ter uma exceção de granularidade.');
            }
        });
    }
}
