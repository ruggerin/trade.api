<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanogramaPrateleira extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'planograma_prateleiras';

    protected $fillable = ['planograma_id', 'ordem', 'descricao', 'quantidade_blocos'];

    public function planograma(): BelongsTo
    {
        return $this->belongsTo(Planograma::class);
    }

    public function blocos(): HasMany
    {
        return $this->hasMany(PlanogramaBloco::class, 'prateleira_id')->orderBy('posicao_inicio');
    }
}
