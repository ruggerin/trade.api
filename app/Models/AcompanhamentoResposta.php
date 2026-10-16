<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma resposta ao "ainda está lá?" (docs/83). Sem empresa própria: herda do acompanhamento. */
class AcompanhamentoResposta extends Model
{
    use HasUuid;

    public const CONTINUA = 'CONTINUA';

    public const ACABOU = 'ACABOU';

    public const NAO_CONSEGUI_VER = 'NAO_CONSEGUI_VER';

    protected $table = 'acompanhamento_respostas';

    protected $fillable = ['acompanhamento_id', 'visita_id', 'usuario_id', 'resposta', 'registro_id', 'respondido_em', 'aplicada', 'idempotency_key'];

    protected function casts(): array
    {
        return ['respondido_em' => 'datetime', 'aplicada' => 'boolean'];
    }

    public function acompanhamento(): BelongsTo
    {
        return $this->belongsTo(AcompanhamentoRegistro::class, 'acompanhamento_id');
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function registro(): BelongsTo
    {
        return $this->belongsTo(VisitaRegistro::class, 'registro_id');
    }
}
