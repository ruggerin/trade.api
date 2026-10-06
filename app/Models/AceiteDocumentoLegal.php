<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aceite de uma versão de documento legal — docs/58 §4.2. Append-only: sem edição nem exclusão,
 * é a prova de quem aceitou qual texto, quando e de onde.
 */
class AceiteDocumentoLegal extends Model
{
    protected $table = 'aceites_documentos_legais';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'documento_legal_id',
        'aceito_em',
        'ip',
        'user_agent',
        'app',
        'dispositivo_identificador',
    ];

    protected function casts(): array
    {
        return [
            'aceito_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function documentoLegal(): BelongsTo
    {
        return $this->belongsTo(DocumentoLegal::class);
    }
}
