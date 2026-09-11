<?php

namespace App\Http\Requests\ProdutoAuditoria;

use App\Enums\Propriedade;
use App\Support\CodigoBarrasProduto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service (mobile): o promotor cadastra um produto que ainda não existe no catálogo,
 * durante a visita — mesmas regras de StoreProdutoAuditoriaRequest, sem exigir
 * `catalogo.gerenciar`. Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.3. Código de barras
 * obrigatório/único usa os MESMOS parâmetros de empresa do cadastro pelo admin web
 * (CODIGO_BARRAS_OBRIGATORIO/CODIGO_BARRAS_UNICO) — uma regra só, vale nos dois lugares.
 *
 * Sem campo `produto_final` aqui (diferente do admin) — não é um descuido: o self-service do
 * promotor nunca cadastra a combinação genérica "seção × marca" (isso só existe como atalho de
 * autoria de campanha no admin web, via `gerar_via_secoes_marcas`), então todo produto criado
 * por aqui já É, por natureza, um SKU específico. A obrigatoriedade do código de barras
 * (CodigoBarrasProduto::obrigatorio) continua valendo sem essa condição extra.
 */
class StoreProdutoAuditoriaPropriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresa = $this->user()->empresa;

        $codigoBarras = [
            'nullable', 'string', 'max:64',
            Rule::requiredIf(fn () => CodigoBarrasProduto::obrigatorio($empresa)),
        ];
        if (CodigoBarrasProduto::unico($empresa)) {
            $codigoBarras[] = Rule::unique('produtos_auditoria', 'codigo_barras')->where('empresa_id', $empresa->id);
        }

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'codigo_barras' => $codigoBarras,
            'imagem_url' => ['nullable', 'string', 'max:2048'],
            'departamento_uuid' => [
                'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'secao_uuid' => [
                'nullable', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'peso_kg' => ['nullable', 'numeric'],
            'propriedade' => ['required', Rule::enum(Propriedade::class)],
        ];
    }
}
