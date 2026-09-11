<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartamentoAuditoria extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'departamentos_auditoria';

    protected $fillable = ['empresa_id', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function secoes(): HasMany
    {
        return $this->hasMany(SecaoAuditoria::class, 'departamento_id');
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ProdutoAuditoria::class, 'departamento_id');
    }

    public function marcas(): BelongsToMany
    {
        return $this->belongsToMany(MarcaAuditoria::class, 'marcas_departamentos', 'departamento_id', 'marca_id');
    }
}
