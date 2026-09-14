<?php

namespace App\Models;

use App\Enums\TipoItemCampanha;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tem uuid (usado na rota de imagem) mas não BelongsToEmpresa — herda o isolamento de Visita
 * via visita_id, ver docs/01-MODELO-DE-DADOS.md.
 */
class VisitaRegistro extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'visita_registros';

    protected $fillable = [
        'visita_id',
        'idempotency_key',
        'produto_auditoria_id',
        'tipo_registro_id',
        'tipo_vinculo',
        'secao_id',
        'departamento_id',
        'marca_id',
        'ruptura',
        'observacao',
        'imagem_path',
        'valores_campos',
        // Só o próprio VisitaRegistroController::cancelar escreve aqui — soft, nunca hard
        // delete (mantém o registro e a foto como rastro histórico do que foi cancelado).
        'cancelado_em',
        // Resolução de alerta (Painel de Atividades) — mesmo raciocínio soft-state de
        // cancelado_em, só tem efeito visível quando a empresa liga o Parametro
        // ATIVIDADES_ALERTA_REQUER_RESOLUCAO. Ver VisitaRegistroController::resolverAlerta.
        'alerta_resolvido_em',
        'alerta_resolvido_por_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_vinculo' => TipoItemCampanha::class,
            'ruptura' => 'boolean',
            'valores_campos' => 'array',
            'cancelado_em' => 'datetime',
            'alerta_resolvido_em' => 'datetime',
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'alerta_resolvido_por_id');
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }

    public function produtoAuditoria(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_auditoria_id');
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class);
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class);
    }
}
