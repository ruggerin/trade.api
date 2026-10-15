<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita fora da agenda autorizada pelo gestor (docs/74 §3): uso único, numa loja, com prazo. Ver
 * App\Http\Controllers\VisitaController::liberar e o check-in (`liberacao_uuid`).
 */
class LiberacaoVisita extends Model
{
    use BelongsToEmpresa;
    use HasUuid;

    /** Liberação vale até o fim do turno — longa o bastante pra fila offline enviar o check-in. */
    public const HORAS_VALIDADE = 12;

    protected $table = 'liberacoes_visita';

    protected $fillable = ['empresa_id', 'usuario_id', 'ponto_venda_id', 'autorizada_por_id', 'motivo', 'expira_em', 'usada_em', 'visita_id'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'usada_em' => 'datetime'];
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizada_por_id');
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class);
    }

    /** Ainda não usada e dentro do prazo, deste promotor nesta loja. */
    public function scopeDisponivel(Builder $query, Usuario $usuario, PontoVenda $pontoVenda): Builder
    {
        return $query->where('usuario_id', $usuario->id)
            ->where('ponto_venda_id', $pontoVenda->id)
            ->whereNull('usada_em')
            ->where('expira_em', '>', now());
    }
}
