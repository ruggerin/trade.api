<?php

namespace App\Http\Requests\VisitaRegistro;

use App\Enums\GranularidadeResposta;
use App\Enums\MomentoRegistro;
use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Support\GranularidadeChecklist;
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

        return [
            'imagem' => ['nullable', 'file', 'image', 'max:8192'],
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
            // Livre mesmo com produto vinculado — o mesmo produto pode ter mais de um registro
            // na mesma visita (antes, depois, um "Ponto extra" novo, etc.), cada um seu próprio
            // momento opcional. Antes proibia junto de produto_auditoria_uuid; mudou porque
            // "antes/depois" faz sentido tanto pro registro geral quanto por produto específico.
            'momento' => ['nullable', Rule::enum(MomentoRegistro::class)],
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

            if ($tipoRegistro->exige_foto && ! $this->hasFile('imagem')) {
                $validator->errors()->add('imagem', "Obrigatória para o tipo de registro \"{$tipoRegistro->descricao}\".");
            }

            $this->validarGranularidade($validator, $tipoRegistro);

            $valores = $this->input('valores_campos', []);

            foreach ($tipoRegistro->campos as $campo) {
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
            }
        });
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
