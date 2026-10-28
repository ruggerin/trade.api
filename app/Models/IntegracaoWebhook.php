<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Endereço do sistema do cliente que recebe os avisos de uma integração (docs/96 Fase 4). O
 * segredo assina cada aviso (HMAC-SHA256); fica criptografado porque precisa ser lido pra assinar,
 * e só sai em texto na criação. Não é tenant-aware por conta própria: sempre lido pela Integracao.
 */
class IntegracaoWebhook extends Model
{
    use HasUuid;

    protected $table = 'integracao_webhooks';

    protected $fillable = ['integracao_id', 'url', 'eventos', 'segredo', 'ativo'];

    protected $hidden = ['segredo'];

    protected function casts(): array
    {
        return [
            'eventos' => 'array',
            'segredo' => 'encrypted',
            'ativo' => 'boolean',
            'falhas_seguidas' => 'integer',
            'desativado_em' => 'datetime',
            'ultima_entrega_em' => 'datetime',
        ];
    }

    public function integracao(): BelongsTo
    {
        return $this->belongsTo(Integracao::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(IntegracaoWebhookEntrega::class, 'webhook_id');
    }
}
