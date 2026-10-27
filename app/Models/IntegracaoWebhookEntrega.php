<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um aviso na fila de um webhook (docs/96 Fase 4) — ver App\Support\Webhooks. */
class IntegracaoWebhookEntrega extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'integracao_webhook_entregas';

    protected $fillable = ['webhook_id', 'evento', 'payload', 'proxima_tentativa_em'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'tentativas' => 'integer',
            'proxima_tentativa_em' => 'datetime',
            'entregue_em' => 'datetime',
            'desistiu_em' => 'datetime',
            'ultimo_status' => 'integer',
            'ultima_duracao_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(IntegracaoWebhook::class, 'webhook_id');
    }
}
