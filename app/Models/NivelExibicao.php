<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NivelExibicao extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'niveis_exibicao';

    protected $fillable = ['empresa_id', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ProdutoAuditoria::class, 'nivel_exibicao_id');
    }
}
