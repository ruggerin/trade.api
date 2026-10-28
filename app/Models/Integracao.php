<?php

namespace App\Models;

use App\Enums\EscopoIntegracao;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Sistema do cliente (ERP, BI) que acessa /api/integracao/v1 com token próprio — docs/96 §4.1.
 * É o `tokenable` do Sanctum no lugar de um Usuario: o token é da empresa e não cai quando alguém
 * sai ou troca a senha. Estende Authenticatable só porque o guard do Sanctum devolve o dono do
 * token como "usuário" da requisição; nunca faz login.
 *
 * Com ela autenticada, `auth()->user()->empresa_id` é o da integração, então o BelongsToEmpresa
 * filtra as consultas da API de integração pela empresa dela, igual faz pro usuário.
 */
class Integracao extends Authenticatable
{
    use BelongsToEmpresa, HasApiTokens, HasUuid;

    protected $table = 'integracoes';

    protected $fillable = [
        'empresa_id',
        'nome',
        'descricao',
        'escopos',
        'ips_permitidos',
        'limite_por_minuto',
        'ativa',
        'criada_por_id',
    ];

    protected function casts(): array
    {
        return [
            'escopos' => 'array',
            'ips_permitidos' => 'array',
            'limite_por_minuto' => 'integer',
            'ativa' => 'boolean',
            'revogada_em' => 'datetime',
            'ultimo_uso_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criada_por_id');
    }

    public function revogadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revogada_por_id');
    }

    public function requisicoes(): HasMany
    {
        return $this->hasMany(IntegracaoRequisicao::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(IntegracaoWebhook::class);
    }

    public function temEscopo(EscopoIntegracao $escopo): bool
    {
        return in_array($escopo->value, $this->escopos ?? [], true);
    }

    public function revogada(): bool
    {
        return $this->revogada_em !== null;
    }
}
