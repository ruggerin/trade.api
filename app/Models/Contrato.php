<?php

namespace App\Models;

use App\Enums\TipoContrato;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contrato extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $fillable = [
        'empresa_id',
        'ponto_venda_id',
        'tipo',
        'descricao',
        'vigencia_inicio',
        'vigencia_fim',
        'arquivo_path',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoContrato::class,
            'vigencia_inicio' => 'datetime',
            'vigencia_fim' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class);
    }

    /**
     * Metas de contrapartida comercial negociadas dentro deste contrato — ver
     * docs/09-CONTRATO-METAS.md.
     */
    public function metas(): HasMany
    {
        return $this->hasMany(ContratoMeta::class)->latest('periodo_inicio');
    }

    /**
     * Log de alterações do contrato — ver App\Models\ContratoHistorico.
     */
    public function historicos(): HasMany
    {
        return $this->hasMany(ContratoHistorico::class)->latest();
    }
}
