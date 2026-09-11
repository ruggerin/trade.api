<?php

namespace App\Http\Requests\ProdutoAuditoria;

use App\Enums\Propriedade;
use App\Support\CodigoBarrasProduto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProdutoAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresa = $this->user()->empresa;

        // Sem `required` aqui de propósito — CODIGO_BARRAS_OBRIGATORIO (ver
        // App\Support\CodigoBarrasProduto) só se aplica ao "novo cadastro", não trava uma edição
        // não relacionada de um produto antigo que nunca teve código de barras. A unicidade
        // (quando ligada) continua valendo, ignorando o próprio registro.
        $codigoBarras = ['sometimes', 'nullable', 'string', 'max:64'];
        if (CodigoBarrasProduto::unico($empresa)) {
            $codigoBarras[] = Rule::unique('produtos_auditoria', 'codigo_barras')
                ->where('empresa_id', $empresa->id)
                ->ignore($this->route('produtoAuditoria'));
        }

        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'codigo_barras' => $codigoBarras,
            'imagem_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'departamento_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('departamentos_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'secao_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('secoes_auditoria', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'nivel_exibicao_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('niveis_exibicao', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'produto_final' => ['sometimes', 'boolean'],
            'produto_chave' => ['sometimes', 'boolean'],
            'gerar_via_secoes_marcas' => ['sometimes', 'boolean'],
            'peso_kg' => ['sometimes', 'nullable', 'numeric'],
            'propriedade' => ['sometimes', 'required', Rule::enum(Propriedade::class)],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
