<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuração genérica chave/valor por empresa — mobile e admin web baixam e guardam em
 * cache local, evitando requisição repetida. `valor` é sempre string; quem consome decide
 * como interpretar (int, bool, json) com base na convenção de cada `chave`.
 */
class Parametro extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $fillable = ['empresa_id', 'chave', 'valor', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
