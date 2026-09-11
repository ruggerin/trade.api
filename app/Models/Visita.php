<?php

namespace App\Models;

use App\Enums\CheckoutTipo;
use App\Enums\StatusVisita;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visita extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $fillable = [
        'empresa_id',
        'ponto_venda_id',
        'usuario_id',
        'campanha_id',
        'ordem_servico_id',
        'idempotency_key',
        'status',
        'inicio_data',
        'inicio_latitude',
        'inicio_longitude',
        'inicio_distancia_metros',
        'fim_data',
        'fim_latitude',
        'fim_longitude',
        'fim_distancia_metros',
        'checkout_tipo',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusVisita::class,
            'checkout_tipo' => CheckoutTipo::class,
            'inicio_data' => 'datetime',
            'fim_data' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class, 'ponto_venda_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(CampanhaAuditoria::class, 'campanha_id');
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class, 'ordem_servico_id');
    }

    public function registros(): HasMany
    {
        return $this->hasMany(VisitaRegistro::class);
    }

    /**
     * Log de intervenções administrativas (cancelamento, checkout forçado, correção de horário)
     * — ver App\Models\VisitaIntervencao e docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
     */
    public function intervencoes(): HasMany
    {
        return $this->hasMany(VisitaIntervencao::class)->latest();
    }
}
