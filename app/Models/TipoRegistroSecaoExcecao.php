<?php

namespace App\Models;

use App\Enums\GranularidadeResposta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exceção de granularidade por (TipoRegistro × Seção) — ver
 * docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4. Sem `uuid`/`empresa_id` próprios: é uma
 * tabela puramente derivada, isolamento herdado de `tipo_registro_id`, sem endpoint próprio
 * (gerida só através de TipoRegistro, mesmo padrão de `CampoTipoRegistro`).
 */
class TipoRegistroSecaoExcecao extends Model
{
    protected $table = 'tipo_registro_secao_excecoes';

    protected $fillable = ['tipo_registro_id', 'secao_auditoria_id', 'granularidade'];

    protected function casts(): array
    {
        return [
            'granularidade' => GranularidadeResposta::class,
        ];
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class, 'secao_auditoria_id');
    }
}
