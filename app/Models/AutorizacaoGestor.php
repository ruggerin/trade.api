<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de 6 dígitos que um gestor gera no admin web e passa por telefone pro promotor, pra
 * autorizar o cancelamento de uma visita travada sem precisar digitar e-mail e senha no aparelho
 * dele — ver App\Http\Controllers\AutorizacaoGestorController,
 * App\Http\Controllers\VisitaController::cancelarAutorizado e
 * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md §12.
 */
class AutorizacaoGestor extends Model
{
    protected $table = 'autorizacoes_gestor';

    protected $fillable = ['empresa_id', 'usuario_id', 'codigo', 'expira_em', 'usado_em'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'usado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Código válido pra esta empresa: existe, não expirou, ainda não foi usado. */
    public function scopeValido(Builder $query, int $empresaId, string $codigo): Builder
    {
        return $query->where('empresa_id', $empresaId)
            ->where('codigo', $codigo)
            ->whereNull('usado_em')
            ->where('expira_em', '>', now());
    }
}
