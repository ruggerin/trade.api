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
            // Só tem efeito quando tipo_campo = DATA — ver docs/35-LIMITE-RETROATIVO-CAMPO-DATA.md.
            // Vazio/null = sem limite (aceita qualquer data passada).
            'campos.*.limite_dias_retroativos' => ['nullable', 'integer', 'min:0'],
            // Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — referencia
            // a `chave` de outro campo DESTE MESMO array (não um uuid — o campo pai pode ser novo,
            // ainda sem id, na mesma requisição). Validado contra o array inteiro em withValidator
            // abaixo (precisa existir e vir antes na ordem).
            'campos.*.depende_de_chave' => ['nullable', 'string', 'max:50'],
            'campos.*.depende_de_valor' => ['required_with:campos.*.depende_de_chave', 'nullable', 'string'],
            // Campo SORTIMENTO (decisão 3 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — só faz
            // sentido quando campos.*.tipo_campo = SORTIMENTO, mas isso não é reforçado aqui (o
            // cliente que não deveria mandar esses campos fora desse tipo); validação de
            // presença condicional roda em withValidator abaixo, igual ao resto do request.
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
            // % de campos BOOLEANO/SORTIMENTO que "passaram" — ver
            // VisitaRegistroResource::pontuacao e decisão 5 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
            'usa_pontuacao' => ['nullable', 'boolean'],
            // Controla se o tipo aparece solto no dropdown de "criar registro" do promotor,
            // além de Ação/formulário de campanha — decisão 8 do mesmo documento.
            'disponivel_registro_livre' => ['nullable', 'boolean'],
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

            $this->validarCondicional($validator);

            $secoesExcecao = collect($this->input('excecoes_granularidade', []))->pluck('secao_uuid')->filter();
            if ($secoesExcecao->count() !== $secoesExcecao->unique()->count()) {
                $validator->errors()->add('excecoes_granularidade', 'Cada seção só pode ter uma exceção de granularidade.');
            }
        });
    }

    /**
     * Campo condicional só pode depender de um campo ANTERIOR na `ordem` do formulário (a
     * cadeia sempre desce, nunca uma pergunta de baixo condiciona uma de cima) — trava que,
     * sozinha, também evita dependência circular (um grafo onde toda aresta aponta pra um índice
     * menor nunca tem ciclo), ver decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
     */
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
