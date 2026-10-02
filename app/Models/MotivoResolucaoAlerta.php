<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotivoResolucaoAlerta extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'motivos_resolucao_alerta';

    protected $fillable = ['empresa_id', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
