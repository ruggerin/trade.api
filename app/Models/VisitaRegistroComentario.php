<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sem BelongsToEmpresa — herda o isolamento do registro/visita, como as demais tabelas filhas. */
class VisitaRegistroComentario extends Model
{
    use HasUuid;

    protected $table = 'visita_registro_comentarios';

    protected $fillable = ['visita_registro_id', 'usuario_id', 'texto'];

    public function registro(): BelongsTo
    {
        return $this->belongsTo(VisitaRegistro::class, 'visita_registro_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
