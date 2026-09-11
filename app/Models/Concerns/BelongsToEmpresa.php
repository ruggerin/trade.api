<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Isolamento multi-tenant: toda query em um Model tenant-aware é automaticamente filtrada
 * pela empresa do usuário autenticado, e todo create() preenche empresa_id sozinho — ver
 * docs/02-API-BACKEND.md#multi-tenancy-e-isolamento-de-dados.
 *
 * O filtro só entra em ação quando há um usuário autenticado com empresa_id definido. Isso
 * cobre os dois casos em que ele deve ficar inerte sem precisar de withoutGlobalScope():
 * contexto de console/seeder (sem usuário autenticado) e usuário SUPERADMIN (empresa_id null,
 * não pertence a nenhum tenant). Rotas /api/superadmin/* ainda devem preferir
 * withoutGlobalScope() explicitamente, pela clareza de intenção.
 */
trait BelongsToEmpresa
{
    public static function bootBelongsToEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $query): void {
            $user = auth()->user();

            if ($user && $user->empresa_id !== null) {
                $query->where($query->getModel()->getTable().'.empresa_id', $user->empresa_id);
            }
        });

        static::creating(function (self $model): void {
            if (is_null($model->empresa_id) && ($user = auth()->user()) && $user->empresa_id !== null) {
                $model->empresa_id = $user->empresa_id;
            }
        });
    }
}
