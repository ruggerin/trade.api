<?php

namespace App\Models\Concerns;

/**
 * Identificador público das tabelas tenant-aware. O uuid é gerado nativamente pelo Postgres
 * (DEFAULT gen_random_uuid() na coluna, ver migrations) — o Eloquent só recebe o `id` de volta
 * no INSERT ... RETURNING, então o objeto em memória não sabe o valor gerado (nem o de
 * qualquer outra coluna com DEFAULT do banco, como `ativo`). Por isso recarregamos o model
 * inteiro do banco logo após criar — assim `uuid`, `ativo` e afins já vêm preenchidos, sem
 * exigir um refresh() manual em cada controller.
 *
 * `id` (bigint) continua sendo a chave primária real, usada só internamente (FK, joins) —
 * nunca aparece em rota ou resposta de API. Ver docs/01-MODELO-DE-DADOS.md#identificador-público-uuid.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::created(function (self $model): void {
            $fresh = $model->newQueryWithoutScopes()->whereKey($model->getKey())->firstOrFail();

            $model->setRawAttributes($fresh->getAttributes());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
