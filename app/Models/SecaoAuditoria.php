<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecaoAuditoria extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'secoes_auditoria';

    protected $fillable = ['empresa_id', 'departamento_id', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'departamento_id');
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ProdutoAuditoria::class, 'secao_id');
    }
}
