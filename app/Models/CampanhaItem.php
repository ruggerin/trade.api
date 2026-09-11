<?php

namespace App\Models;

use App\Enums\TipoItemCampanha;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tem uuid (endpoint próprio de remoção) mas não BelongsToEmpresa — herda o isolamento de
 * CampanhaAuditoria via campanha_id, ver docs/01-MODELO-DE-DADOS.md.
 */
class CampanhaItem extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'campanha_itens';

    protected $fillable = [
        'campanha_id',
        'tipo_item',
        'produto_id',
        'departamento_id',
        'secao_id',
        'marca_id',
    ];

    protected function casts(): array
    {
        return ['tipo_item' => TipoItemCampanha::class];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(CampanhaAuditoria::class, 'campanha_id');
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
}
