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

    protected $fillable = [
        'tipo_registro_id', 'chave', 'rotulo', 'tipo_campo', 'opcoes', 'obrigatorio', 'ordem',
        'depende_de_campo_id', 'depende_de_valor',
    ];

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

    /** Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — só preenchido quando este campo depende de outro do mesmo TipoRegistro. */
    public function dependeDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depende_de_campo_id');
    }
}
