<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Foto pertence à Visita, não a um VisitaRegistro específico — o vínculo com qual(is)
 * resposta(s) ela evidencia mora na pivot `visita_registro_imagem`. É isso que permite 1 foto
 * evidenciar N respostas, e 1 resposta ter N fotos. Ver docs/21-EVIDENCIA-EM-FOTOS.md.
 */
class ImagemRegistro extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'imagens_registro';

    public $timestamps = false;

    protected $fillable = ['visita_id', 'caminho'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function registros(): BelongsToMany
    {
        return $this->belongsToMany(VisitaRegistro::class, 'visita_registro_imagem')->withPivot('ordem');
    }
}
