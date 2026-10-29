<?php

namespace App\Support;

use App\Models\DepartamentoAuditoria;
use App\Models\MarcaAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;

/**
 * Lista guiada de seções/linhas (docs/100): no formulário "Cada seção / linha", o admin escolhe o
 * nível (seção, departamento ou marca) e a origem — o que cada loja tem no Mix (o app calcula
 * offline, com o Mix em cache) ou uma lista fixa, resolvida aqui com os nomes.
 */
final class LinhasGuiadas
{
    /** @return array<int, array{id: string, descricao: string}>|null null = sem lista fixa. */
    public static function fixas(TipoRegistro $tipo): ?array
    {
        if (! $tipo->linha_nivel || $tipo->linha_origem !== 'FIXA' || empty($tipo->linhas_fixas)) {
            return null;
        }

        $modelo = match ($tipo->linha_nivel) {
            'SECAO' => SecaoAuditoria::class,
            'DEPARTAMENTO' => DepartamentoAuditoria::class,
            'MARCA' => MarcaAuditoria::class,
            default => null,
        };
        if (! $modelo) {
            return null;
        }

        $nomes = $modelo::query()->withoutGlobalScopes()
            ->where('empresa_id', $tipo->empresa_id)
            ->whereIn('uuid', $tipo->linhas_fixas)
            ->pluck('descricao', 'uuid');

        // Na ordem escolhida no cadastro; o que foi apagado do catálogo some.
        return collect($tipo->linhas_fixas)
            ->filter(fn ($uuid) => $nomes->has($uuid))
            ->map(fn ($uuid) => ['id' => $uuid, 'descricao' => $nomes[$uuid]])
            ->values()
            ->all();
    }
}
