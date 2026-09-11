<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampanhaAuditoria extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'campanhas_auditoria';

    protected $fillable = [
        'empresa_id',
        'descricao',
        'observacao',
        'layout',
        'vigencia_inicio',
        'vigencia_fim',
        'restricao',
        'ativo',
        'exclusividade',
        'frequencia_dias',
        'execucao_recorrente',
        'possui_restricao',
        'possui_exclusividade',
    ];

    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'datetime',
            'vigencia_fim' => 'datetime',
            'ativo' => 'boolean',
            'frequencia_dias' => 'integer',
            'execucao_recorrente' => 'boolean',
            'possui_restricao' => 'boolean',
            'possui_exclusividade' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CampanhaItem::class, 'campanha_id');
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class, 'campanha_id');
    }
}
