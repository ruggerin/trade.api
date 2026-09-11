<?php

namespace App\Models;

use App\Enums\Propriedade;
use App\Enums\StatusAprovacao;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoAuditoria extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'produtos_auditoria';

    protected $fillable = [
        'empresa_id',
        'descricao',
        // Opcional por padrão; pode virar obrigatório/único no cadastro via os parâmetros
        // CODIGO_BARRAS_OBRIGATORIO/CODIGO_BARRAS_UNICO — ver App\Support\CodigoBarrasProduto.
        'codigo_barras',
        'imagem_url',
        'departamento_id',
        'secao_id',
        'nivel_exibicao_id',
        'produto_final',
        // Sem efeito hoje no algoritmo de disponiveis (ver CampanhaAuditoriaController) — no
        // sistema antigo, controlava a geração de itens "seção × marca" pra cobrir uma seção
        // inteira sem cadastrar cada SKU (ver docs/01-MODELO-DE-DADOS.md §5.5). O algoritmo
        // novo (docs/02-API-BACKEND.md, regra de negócio 2) já cobre o mesmo objetivo via
        // campanha_itens.tipo_item = SECAO/DEPARTAMENTO/MARCA, então esse campo ficou sem
        // consumidor — mantido só porque já existe no cadastro/CRUD do admin (ver
        // docs/06-PENDENCIAS.md: precisa de decisão de produto, não é bug nem esquecimento).
        'gerar_via_secoes_marcas',
        'peso_kg',
        'propriedade',
        'ativo',
        // NULL = cadastro normal (admin/gestor); preenchido = criado por um promotor pela
        // visita — ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.2.
        'criado_por_usuario_id',
        'status_aprovacao',
        // Gera aviso nomeado ao finalizar a visita se ficar sem nenhum registro — ver
        // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §6. Só o admin web define, nunca o
        // self-service do promotor.
        'produto_chave',
    ];

    protected function casts(): array
    {
        return [
            'produto_final' => 'boolean',
            'gerar_via_secoes_marcas' => 'boolean',
            'peso_kg' => 'double',
            'propriedade' => Propriedade::class,
            'ativo' => 'boolean',
            'status_aprovacao' => StatusAprovacao::class,
            'produto_chave' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_usuario_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'departamento_id');
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class, 'secao_id');
    }

    public function nivelExibicao(): BelongsTo
    {
        return $this->belongsTo(NivelExibicao::class, 'nivel_exibicao_id');
    }
}
