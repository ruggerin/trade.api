<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Puramente associativa (marca x departamento onde a marca aparece) — sem uuid nem
 * empresa_id próprios, ver docs/01-MODELO-DE-DADOS.md#identificador-público-uuid. Existe
 * como Model dedicado (em vez de só a tabela pivot do belongsToMany) para o caso de precisar
 * de queries diretas mais pra frente.
 */
class MarcaDepartamento extends Model
{
    protected $table = 'marcas_departamentos';

    protected $fillable = ['marca_id', 'departamento_id'];

    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class, 'marca_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'departamento_id');
    }
}
