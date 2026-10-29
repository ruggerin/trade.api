<?php

namespace App\Http\Requests\TipoRegistro;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoCampoRegistro;
use App\Support\IconeTipoRegistro;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\TipoRegistro;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTipoRegistroRequest extends FormRequest
{
    use ValidaRecorteDoMix;

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
            // Escopo LOJA_REDE (docs/40-ACAO-OBRIGATORIA-LOJA-REDE.md) — as duas listas opcionais;
            // ignoradas (e limpas) em qualquer outro escopo.
            // Lista predefinida de produtos (só com granularidade PRODUTO) — ver
            // TipoRegistro::produtosPredefinidos.
            'produtos_uuids' => ['nullable', 'array', 'max:500'],
            'produtos_uuids.*' => ['string', 'distinct', Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id)],
            // Concorrentes por marca e embalagem (docs/85) — só com granularidade MARCA.
            'marcas_uuids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'marcas_uuids.*' => ['string', 'distinct', Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id)],
            'permite_marca_nova' => ['nullable', 'boolean'],
            // Lista guiada da granularidade PRODUTO: fixa (a do admin) ou o Mix da loja (docs/85 §7).
            'lista_produtos_origem' => ['nullable', Rule::in([\App\Models\TipoRegistro::LISTA_PRODUTOS_FIXA, \App\Models\TipoRegistro::LISTA_PRODUTOS_MIX_LOJA])],
            'varias_embalagens' => ['nullable', 'boolean'],
            'pontos_venda_uuids' => ['nullable', 'array', 'max:2000'],
            'pontos_venda_uuids.*' => ['string', 'distinct', Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $this->user()->empresa_id)],
            'redes_lojas_uuids' => ['nullable', 'array', 'max:500'],
            'redes_lojas_uuids.*' => ['string', 'distinct', Rule::exists('redes_lojas', 'uuid')->where('empresa_id', $this->user()->empresa_id)],
            // Escopo LINHA_AUSENTE (docs/80 §5.1) — recorte onde a linha não pode estar no Mix.
            'escopo_tipo_vinculo' => ['required_if:escopo_acao,LINHA_AUSENTE', 'nullable', Rule::in(['SECAO', 'DEPARTAMENTO', 'MARCA'])],
            'escopo_secao_uuid' => [
                'required_if:escopo_tipo_vinculo,SECAO', 'nullable', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'escopo_departamento_uuid' => [
                'required_if:escopo_tipo_vinculo,DEPARTAMENTO', 'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'escopo_marca_uuid' => [
                'required_if:escopo_tipo_vinculo,MARCA', 'nullable', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            // Intervalo entre cobranças da Ação (docs/80 §5.2): 0 = toda visita, N = a cada N dias.
            'intervalo_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            // Filtro da Ação por ramo de atividade da loja (docs/80 §5.4); vazio = todos os ramos.
            'ramos_atividade_uuids' => ['nullable', 'array', 'max:200'],
            'ramos_atividade_uuids.*' => ['string', 'distinct', Rule::exists('ramos_atividade', 'uuid')->where('empresa_id', $this->user()->empresa_id)],
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
            // Obrigatório com origem DINAMICO, exceto quando o mix segue a escolha do promotor —
            // conferido em ValidaRecorteDoMix.
            'campos.*.sortimento_tipo_vinculo' => [
                'nullable',
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
            // Pergunta FOTO (docs/98): quantas fotos, se pode reaproveitar e a dica no app.
            'campos.*.foto_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'campos.*.foto_max' => ['nullable', 'integer', 'min:1', 'max:20'],
            'campos.*.foto_reaproveitar' => ['nullable', Rule::in(['NAO', 'VISITA', 'HISTORICO'])],
            'campos.*.foto_dica' => ['nullable', 'string', 'max:255'],
            // Mix segue o recorte escolhido pelo promotor no registro (docs/79 §4.2).
            'campos.*.sortimento_segue_escolha' => ['nullable', 'boolean'],
            // Granularidade padrão da pergunta (LINHA/PRODUTO) — ver
            // App\Support\GranularidadeChecklist. `null` = sem regra, comportamento livre atual.
            // Promotor escolhe o recorte no início do formulário — ver docs/79 §4.1.
            'escolhe_marca' => ['nullable', 'boolean'],
            'escolhe_departamento' => ['nullable', 'boolean'],
            'escolhe_secao' => ['nullable', 'boolean'],
            // docs/82 §6.1.
            'lista_recorte' => ['nullable', Rule::in([TipoRegistro::LISTA_PRIMEIRO_DA_LOJA, TipoRegistro::LISTA_SO_DA_LOJA])],
            'filtra_marca' => ['nullable', 'boolean'],
            'permite_ruptura' => ['nullable', 'boolean'],
            'permite_foto_livre' => ['nullable', 'boolean'],
            // "Ainda está lá?" (docs/83 §3.1).
            'acompanha' => ['nullable', 'boolean'],
            'acompanha_gatilho_chave' => ['nullable', 'nullable', 'string', 'max:100'],
            'acompanha_gatilho_valor' => ['nullable', 'nullable', 'string', 'max:255'],
            'acompanha_intervalo_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'acompanha_rotulo_continua' => ['nullable', 'nullable', 'string', 'max:60'],
            'acompanha_rotulo_acabou' => ['nullable', 'nullable', 'string', 'max:60'],
            'acompanha_ao_continuar' => ['nullable', Rule::in(\App\Support\Acompanhamentos::AO_CONTINUAR)],
            'acompanha_formulario_acabou_uuid' => [
                'nullable', 'nullable', 'string', 'uuid',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'acompanha_prazo_dias' => ['nullable', 'nullable', 'integer', 'min:1', 'max:3650'],
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
            // Régua de XP (docs/88 §9.1) e avaliação do gestor (doc 33).
            'xp_iniciativa' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'xp_bonus_acao' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'xp_teto_por_visita' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'xp_intervalo_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'exige_avaliacao' => ['nullable', 'boolean'],
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
            $this->validarAcompanhamento($validator);
            $this->validarPorMarca($validator, null);
            $this->validarRecorteDoMix(
                $validator,
                $this->boolean('escolhe_marca') || $this->boolean('escolhe_departamento') || $this->boolean('escolhe_secao'),
            );

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

    /**
     * docs/83 §5.2: rótulos obrigatórios com o acompanhamento ligado; gatilho só com pergunta do
     * próprio formulário que não seja Mix; justificativa diferente do próprio formulário.
     */
    protected function validarAcompanhamento(\Illuminate\Validation\Validator $validator): void
    {
        $atual = $this->route('tipoRegistro');
        $valor = fn (string $campo) => $this->has($campo) ? $this->input($campo) : $atual?->{$campo};
        if (! filter_var($valor('acompanha'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        foreach (['acompanha_rotulo_continua' => 'Continua', 'acompanha_rotulo_acabou' => 'Acabou'] as $campo => $nome) {
            if (trim((string) $valor($campo)) === '') {
                $validator->errors()->add($campo, "Escreva o texto do botão \"{$nome}\".");
            }
        }

        $chave = $valor('acompanha_gatilho_chave');
        if ($chave !== null && $chave !== '') {
            $campos = $this->has('campos')
                ? collect($this->input('campos', []))->map(fn ($c) => ['chave' => $c['chave'] ?? null, 'tipo_campo' => $c['tipo_campo'] ?? null])
                : ($atual?->campos ?? collect())->map(fn ($c) => ['chave' => $c->chave, 'tipo_campo' => $c->tipo_campo?->value ?? $c->tipo_campo]);
            $campo = $campos->firstWhere('chave', $chave);
            if (! $campo) {
                $validator->errors()->add('acompanha_gatilho_chave', 'A pergunta do gatilho precisa ser deste formulário.');
            } elseif ($campo['tipo_campo'] === 'SORTIMENTO') {
                $validator->errors()->add('acompanha_gatilho_chave', 'O gatilho não pode ser uma pergunta de Mix.');
            }
        }

        if ($atual && $this->input('acompanha_formulario_acabou_uuid') === $atual->uuid) {
            $validator->errors()->add('acompanha_formulario_acabou_uuid', 'A justificativa precisa ser outro formulário.');
        }
    }

    /**
     * docs/85 §4.2: com granularidade MARCA, a lista só aceita marcas concorrentes, e o formulário
     * precisa ter alguma marca na lista ou deixar o promotor cadastrar. Exceção por seção não
     * vira MARCA (é granularidade do formulário inteiro).
     */
    private function validarPorMarca(Validator $validator, ?\App\Models\TipoRegistro $atual = null): void
    {
        foreach ((array) $this->input('excecoes_granularidade', []) as $i => $excecao) {
            if (($excecao['granularidade'] ?? null) === GranularidadeResposta::MARCA->value) {
                $validator->errors()->add("excecoes_granularidade.{$i}.granularidade", 'Exceção por seção só pode ser por produto ou por linha.');
            }
        }

        $granularidade = $this->has('granularidade_padrao') ? $this->input('granularidade_padrao') : $atual?->granularidade_padrao?->value;
        if ($granularidade !== GranularidadeResposta::MARCA->value) {
            return;
        }

        $uuids = $this->has('marcas_uuids')
            ? collect((array) $this->input('marcas_uuids', []))->filter(fn ($u) => is_string($u) && \Illuminate\Support\Str::isUuid($u))
            : null;
        if ($uuids !== null && $uuids->isNotEmpty()) {
            $naoConcorrentes = \App\Models\MarcaAuditoria::whereIn('uuid', $uuids)
                ->where('propriedade', '!=', \App\Enums\Propriedade::CONCORRENTE->value)
                ->pluck('descricao');
            if ($naoConcorrentes->isNotEmpty()) {
                $validator->errors()->add('marcas_uuids', 'Só marcas concorrentes entram na lista: '.$naoConcorrentes->join(', ').'.');
            }
        }

        $temMarcas = $uuids !== null ? $uuids->isNotEmpty() : ($atual?->marcasPredefinidas()->exists() ?? false);
        $permiteNova = $this->has('permite_marca_nova') ? $this->boolean('permite_marca_nova') : ($atual?->permite_marca_nova ?? true);
        if (! $temMarcas && ! $permiteNova) {
            $validator->errors()->add('marcas_uuids', 'Escolha as marcas da lista ou deixe o promotor cadastrar marca nova.');
        }
    }
}
