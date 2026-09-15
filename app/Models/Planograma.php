<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Planograma extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'planogramas';

    protected $fillable = ['empresa_id', 'descricao', 'foto_capa_path', 'ativo'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function prateleiras(): HasMany
    {
        return $this->hasMany(PlanogramaPrateleira::class)->orderBy('ordem');
    }
}
