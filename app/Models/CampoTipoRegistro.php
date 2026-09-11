<?php

namespace App\Models;

use App\Enums\TipoCampoRegistro;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sem BelongsToEmpresa própria — herda isolamento de TipoRegistro via tipo_registro_id. Sem
 * controller/rota própria: gerenciado sempre junto do tipo pai, ver TipoRegistroController.
 */
class CampoTipoRegistro extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'campos_tipo_registro';

    protected $fillable = ['tipo_registro_id', 'chave', 'rotulo', 'tipo_campo', 'opcoes', 'obrigatorio', 'ordem'];

    protected function casts(): array
    {
        return [
            'tipo_campo' => TipoCampoRegistro::class,
            'opcoes' => 'array',
            'obrigatorio' => 'boolean',
        ];
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }
}
