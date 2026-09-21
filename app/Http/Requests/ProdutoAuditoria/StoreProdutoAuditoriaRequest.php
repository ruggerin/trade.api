<?php

namespace App\Http\Requests\ProdutoAuditoria;

use App\Enums\Propriedade;
use App\Support\CodigoBarrasProduto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProdutoAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresa = $this->user()->empresa;

        // Obrigatório e/ou único conforme os parâmetros da empresa — ver
        // App\Support\CodigoBarrasProduto. A regra `unique` só entra quando CODIGO_BARRAS_UNICO
        // está ligado; formato livre (sem exigir só dígitos), o pedido foi só obrigatoriedade/
        // unicidade parametrizáveis, não validar EAN/UPC de verdade.
        //
        // Obrigatoriedade só vale quando `produto_final = true` (SKU específico) — um produto
        // que representa uma combinação genérica "seção × marca" (gerar_via_secoes_marcas) não
        // tem código de barras próprio nenhum pra exigir.
        $codigoBarras = [
            'nullable', 'string', 'max:64',
            Rule::requiredIf(fn () => CodigoBarrasProduto::obrigatorio($empresa) && $this->boolean('produto_final')),
        ];
        if (CodigoBarrasProduto::unico($empresa)) {
            $codigoBarras[] = Rule::unique('produtos_auditoria', 'codigo_barras')->where('empresa_id', $empresa->id);
        }

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'codigo_barras' => $codigoBarras,
            'codigo_externo' => ['nullable', 'string', 'max:64'],
            'imagem_url' => ['nullable', 'string', 'max:2048'],
            'departamento_uuid' => [
                'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'secao_uuid' => [
                'nullable', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'marca_uuid' => [
                'nullable', 'string',
                Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'nivel_exibicao_uuid' => [
                'nullable', 'string',
                Rule::exists('niveis_exibicao', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'produto_final' => ['nullable', 'boolean'],
            // Gera aviso nomeado ao finalizar a visita se ficar sem registro — ver
            // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §6. Só o admin web define.
            'produto_chave' => ['nullable', 'boolean'],
            // Quando true, o produto representa uma combinação genérica "seção × marca" em vez
            // de um SKU específico — ver docs/01-MODELO-DE-DADOS.md.
            'gerar_via_secoes_marcas' => ['nullable', 'boolean'],
            'peso_kg' => ['nullable', 'numeric'],
            'propriedade' => ['required', Rule::enum(Propriedade::class)],
        ];
    }
}
