<?php

namespace App\Models;

use App\Enums\StatusAprovacao;
use App\Enums\TipoItemCampanha;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tem uuid (endpoint próprio de remoção) mas não BelongsToEmpresa — herda o isolamento de
 * PontoVenda via ponto_venda_id, mesmo raciocínio de CampanhaItem. Ver
 * docs/14-SORTIMENTO-PONTO-VENDA.md.
 */
class SortimentoPontoVenda extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'sortimentos_ponto_venda';

    protected $fillable = [
        'ponto_venda_id',
        'tipo_item',
        'produto_id',
        'departamento_id',
        'secao_id',
        'marca_id',
        'usuario_id',
        'status_aprovacao',
    ];

    protected function casts(): array
    {
        return [
            'tipo_item' => TipoItemCampanha::class,
            'status_aprovacao' => StatusAprovacao::class,
        ];
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class, 'ponto_venda_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'departamento_id');
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class, 'secao_id');
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class, 'marca_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
