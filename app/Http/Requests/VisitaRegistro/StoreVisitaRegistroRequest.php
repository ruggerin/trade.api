<?php

namespace App\Http\Requests\VisitaRegistro;

use App\Enums\GranularidadeResposta;
use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Models\CampoTipoRegistro;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Visita;
use App\Support\GranularidadeChecklist;
use App\Support\ResolverSortimentoCampo;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVisitaRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;
        /** @var \App\Models\Visita $visita */
        $visita = $this->route('visita');

        return [
            // Dois jeitos de anexar foto, combináveis — ver docs/21-EVIDENCIA-EM-FOTOS.md.
            'imagens' => ['nullable', 'array'],
            'imagens.*' => ['file', 'image', 'max:8192'],
            // Foto já existente NESTA MESMA visita (evidência compartilhada entre respostas) —
            // só cria o vínculo, sem reenviar arquivo.
            'imagens_existentes_uuids' => ['nullable', 'array'],
            'imagens_existentes_uuids.*' => [
                'bail', 'string', 'uuid',
                Rule::exists('imagens_registro', 'uuid')->where('visita_id', $visita?->id),
            ],
            // Escopado por empresa — sem isso, um uuid de outro tenant passava na validação e só
            // quebrava depois no controller (tipo_registro_id é NOT NULL, resolvido via query
            // já tenant-scoped, que devolve null pra um uuid de fora → erro 500 em vez de 422).
            // `bail` + `uuid` ANTES do `exists`: a coluna é do tipo `uuid` no Postgres — mandar
            // uma string que não é um uuid válido faz a query do `exists` quebrar com erro de
            // sintaxe (500) em vez de reprovar a validação (422) se `uuid` não corta o fluxo antes.
            'tipo_registro_uuid' => [
                'bail', 'required', 'string', 'uuid',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $empresaId),
            ],
            'produto_auditoria_uuid' => [
                'bail', 'nullable', 'string', 'uuid',
                Rule::exists('produtos_auditoria', 'uuid')->where('empresa_id', $empresaId),
            ],
            // Idempotência de registro — ver VisitaRegistroController::store. Só formato aqui,
            // de propósito SEM `unique`: o reenvio da mesma chave é o caso esperado (fila de
            // envio retransmitindo depois de perder a resposta), tratado no controller devolvendo
            // o registro já criado em vez de rejeitar ou duplicar.
            'idempotency_key' => ['nullable', 'string', 'uuid'],
            // Vínculo opcional a um recorte mais amplo do catálogo (seção/departamento/marca
            // inteira) — mesmo discriminador de CampanhaItem.tipo_item. Sem isso, o registro
            // continua podendo vincular só a um produto específico via produto_auditoria_uuid
            // acima (fluxo já existente, não muda).
            'tipo_vinculo' => ['nullable', Rule::in(array_column(TipoItemCampanha::cases(), 'value'))],
            'secao_uuid' => [
                'bail', 'nullable', 'string', 'required_if:tipo_vinculo,SECAO', 'uuid',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $empresaId),
            ],
            'departamento_uuid' => [
                'bail', 'nullable', 'string', 'required_if:tipo_vinculo,DEPARTAMENTO', 'uuid',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $empresaId),
            ],
            'marca_uuid' => [
                'bail', 'nullable', 'string', 'required_if:tipo_vinculo,MARCA', 'uuid',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $empresaId),
            ],
            'ruptura' => ['nullable', 'boolean'],
            'observacao' => ['nullable', 'string'],
            // Valores dos campos customizados do tipo_registro escolhido — validados dinamicamente
            // contra a definição de cada campo (ver withValidator abaixo), não dá pra expressar
            // isso como regra estática.
            'valores_campos' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('tipo_vinculo') === TipoItemCampanha::PRODUTO->value && ! $this->filled('produto_auditoria_uuid')) {
                $validator->errors()->add('produto_auditoria_uuid', 'Obrigatório quando tipo_vinculo é PRODUTO.');
            }

            if (! $this->filled('tipo_registro_uuid')) {
                return;
            }

            $tipoRegistro = TipoRegistro::withoutGlobalScopes()
                ->with(['campos', 'excecoesGranularidade'])
                ->where('uuid', $this->input('tipo_registro_uuid'))
                ->first();

            if (! $tipoRegistro) {
                return;
            }

            $totalImagens = ($this->hasFile('imagens') ? count($this->file('imagens')) : 0)
                + count($this->input('imagens_existentes_uuids', []));
            if ($tipoRegistro->exige_foto && $totalImagens === 0) {
                $validator->errors()->add('imagens', "Obrigatória para o tipo de registro \"{$tipoRegistro->descricao}\".");
            }

            $this->validarGranularidade($validator, $tipoRegistro);

            $valores = $this->input('valores_campos', []);

            foreach ($tipoRegistro->campos as $campo) {
                // Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — sem
                // a condição satisfeita, a pergunta não deveria nem ter aparecido pro promotor:
                // não é checada como obrigatória, e nenhuma validação de tipo roda sobre ela
                // (um valor enviado mesmo assim é descartado depois, ver passedValidation()).
                if ($campo->depende_de_campo_id) {
                    $campoPai = $tipoRegistro->campos->firstWhere('id', $campo->depende_de_campo_id);
                    $condicaoSatisfeita = $campoPai && (($valores[$campoPai->chave] ?? null) === $campo->depende_de_valor);
                    if (! $condicaoSatisfeita) {
                        continue;
                    }
                }

                $valor = $valores[$campo->chave] ?? null;

                if ($campo->obrigatorio && ($valor === null || $valor === '')) {
                    $validator->errors()->add("valores_campos.{$campo->chave}", "O campo \"{$campo->rotulo}\" é obrigatório.");
                    continue;
                }

                if ($valor === null || $valor === '') {
                    continue;
                }

                if (in_array($campo->tipo_campo, [TipoCampoRegistro::NUMERO, TipoCampoRegistro::MOEDA], true) && ! is_numeric($valor)) {
                    $validator->errors()->add("valores_campos.{$campo->chave}", "O campo \"{$campo->rotulo}\" precisa ser numérico.");
                }

                if ($campo->tipo_campo === TipoCampoRegistro::MULTIPLA_ESCOLHA && ! in_array($valor, $campo->opcoes ?? [], true)) {
                    $validator->errors()->add("valores_campos.{$campo->chave}", "Valor inválido pro campo \"{$campo->rotulo}\".");
                }

                // "0"/"1" — mesma convenção do campo `ruptura` já existente (nunca "true"/"false").
                if ($campo->tipo_campo === TipoCampoRegistro::BOOLEANO && ! in_array($valor, ['0', '1'], true)) {
                    $validator->errors()->add("valores_campos.{$campo->chave}", "Valor inválido pro campo \"{$campo->rotulo}\".");
                }

                if ($campo->tipo_campo === TipoCampoRegistro::DATA && ! $this->ehDataValida($valor)) {
                    $validator->errors()->add("valores_campos.{$campo->chave}", "O campo \"{$campo->rotulo}\" precisa ser uma data válida no formato dd/mm/aaaa.");
                }

                if ($campo->tipo_campo === TipoCampoRegistro::SORTIMENTO) {
                    $this->validarSortimento($validator, $campo, $valor);
                }
            }
        });
    }

    /**
     * Valor sempre `{"presentes":[uuid...],"ausentes":[uuid...]}` — cada uuid precisa existir no
     * checklist resolvido pra este campo + PDV da visita (App\Support\ResolverSortimentoCampo),
     * mesmo raciocínio de tenant-scoping usado nos outros campos deste request. Ver decisão 3 de
     * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
     */
    private function validarSortimento(Validator $validator, CampoTipoRegistro $campo, string $valor): void
    {
        $decodificado = json_decode($valor, true);
        if (
            ! is_array($decodificado)
            || ! is_array($decodificado['presentes'] ?? null)
            || ! is_array($decodificado['ausentes'] ?? null)
        ) {
            $validator->errors()->add("valores_campos.{$campo->chave}", "Valor inválido pro campo \"{$campo->rotulo}\".");

            return;
        }

        $presentes = collect($decodificado['presentes']);
        $ausentes = collect($decodificado['ausentes']);

        if ($presentes->intersect($ausentes)->isNotEmpty()) {
            $validator->errors()->add("valores_campos.{$campo->chave}", "Um produto não pode estar marcado como presente e ausente ao mesmo tempo.");

            return;
        }

        /** @var Visita|null $visita */
        $visita = $this->route('visita');
        $pontoVenda = $visita?->pontoVenda;

        $produtosValidos = $pontoVenda
            ? ResolverSortimentoCampo::resolver($campo, $pontoVenda)->pluck('uuid')
            : collect();

        if ($presentes->merge($ausentes)->diff($produtosValidos)->isNotEmpty()) {
            $validator->errors()->add("valores_campos.{$campo->chave}", "Um ou mais produtos não fazem parte do sortimento configurado pra este campo.");
        }
    }

    /** dd/mm/aaaa estrito — Carbon::createFromFormat aceita "31/02/2026" e normaliza pra março, então confere o round-trip pra rejeitar datas inválidas de verdade. */
    private function ehDataValida(string $valor): bool
    {
        try {
            return Carbon::createFromFormat('d/m/Y', $valor)?->format('d/m/Y') === $valor;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sobrescreve (não `passedValidation()` + `merge()` — o Validator já tirou um snapshot dos
     * dados antes disso rodar, `merge()` só atualiza o request em si, `validated()` continua
     * devolvendo o valor original) pra filtrar `valores_campos`, mantendo só respostas de campos
     * cuja condição (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) foi de fato
     * satisfeita — descarta qualquer valor enviado pra uma pergunta que não deveria ter aparecido
     * (ver comentário no withValidator acima). `VisitaRegistroController::store` usa
     * `$request->validated()`, então o valor filtrado aqui é o que efetivamente é gravado.
     */
    public function validated($key = null, $default = null)
    {
        $dados = parent::validated();

        if (array_key_exists('valores_campos', $dados)) {
            $dados['valores_campos'] = $this->filtrarValoresCampos($dados['valores_campos'] ?? []);
        }

        return $key === null ? $dados : data_get($dados, $key, $default);
    }

    private function filtrarValoresCampos(array $valores): array
    {
        if (! $this->filled('tipo_registro_uuid')) {
            return $valores;
        }

        $tipoRegistro = TipoRegistro::withoutGlobalScopes()
            ->with('campos')
            ->where('uuid', $this->input('tipo_registro_uuid'))
            ->first();

        if (! $tipoRegistro) {
            return $valores;
        }

        $filtrados = [];

        foreach ($tipoRegistro->campos as $campo) {
            if (! array_key_exists($campo->chave, $valores)) {
                continue;
            }

            if ($campo->depende_de_campo_id) {
                $campoPai = $tipoRegistro->campos->firstWhere('id', $campo->depende_de_campo_id);
                $condicaoSatisfeita = $campoPai && (($valores[$campoPai->chave] ?? null) === $campo->depende_de_valor);
                if (! $condicaoSatisfeita) {
                    continue;
                }
            }

            $filtrados[$campo->chave] = $valores[$campo->chave];
        }

        return $filtrados;
    }

    /**
     * Ver App\Support\GranularidadeChecklist e docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4.
     * A seção conhecida vem do produto vinculado (produto tem uma seção fixa) ou de um vínculo
     * direto a SECAO — sem nenhum dos dois, só o padrão do tipo (sem exceção possível) se aplica.
     */
    private function validarGranularidade(Validator $validator, TipoRegistro $tipoRegistro): void
    {
        // Str::isUuid antes de qualquer query — a coluna é `uuid` nativa no Postgres, e um valor
        // mal formado (já rejeitado pela regra `uuid` do campo, mas o `after()` roda de qualquer
        // jeito) quebra a query com erro de sintaxe (500) em vez de só não achar nada.
        $produtoUuid = $this->input('produto_auditoria_uuid');
        $produto = ($produtoUuid && Str::isUuid($produtoUuid))
            ? ProdutoAuditoria::withoutGlobalScopes()->where('uuid', $produtoUuid)->first()
            : null;

        $secaoAuditoriaId = $produto?->secao_id;
        $secaoUuid = $this->input('secao_uuid');
        if (! $secaoAuditoriaId && $this->input('tipo_vinculo') === TipoItemCampanha::SECAO->value && $secaoUuid && Str::isUuid($secaoUuid)) {
            $secaoAuditoriaId = SecaoAuditoria::withoutGlobalScopes()->where('uuid', $secaoUuid)->value('id');
        }

        $granularidade = GranularidadeChecklist::resolver($tipoRegistro, $secaoAuditoriaId);

        if ($granularidade === GranularidadeResposta::PRODUTO && ! $produto) {
            $validator->errors()->add(
                'produto_auditoria_uuid',
                "O tipo de registro \"{$tipoRegistro->descricao}\" exige um produto específico, não aceita resposta pra seção/departamento/marca inteira.",
            );
        }
    }
}
