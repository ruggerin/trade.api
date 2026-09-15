<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanogramaBloco extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'planograma_blocos';

    protected $fillable = ['prateleira_id', 'posicao_inicio', 'largura', 'produto_auditoria_id'];

    public function prateleira(): BelongsTo
    {
        return $this->belongsTo(PlanogramaPrateleira::class, 'prateleira_id');
    }

    public function produtoAuditoria(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class);
    }
}
