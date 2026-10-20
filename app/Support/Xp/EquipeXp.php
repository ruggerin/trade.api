<?php

namespace App\Support\Xp;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Nível "equipe" da régua e grupo da liga (docs/88 §11, §12.4): os promotores de um supervisor
 * (`usuarios.supervisor_id`). Quem não tem supervisor compete com os demais sem supervisor — numa
 * empresa sem supervisores cadastrados, isso é a empresa inteira.
 */
final class EquipeXp
{
    /** @return Collection<int, Usuario> promotores ativos do grupo */
    public static function membros(Empresa $empresa, ?int $supervisorId): Collection
    {
        return Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('user_type', UserType::PROMOTOR)
            ->where('ativo', true)
            ->when($supervisorId, fn ($q) => $q->where('supervisor_id', $supervisorId), fn ($q) => $q->whereNull('supervisor_id'))
            ->orderBy('nome')
            ->get();
    }

    /** @return Collection<int, Usuario> promotores da empresa, ou só os da equipe filtrada */
    public static function promotores(Empresa $empresa, ?int $supervisorId = null): Collection
    {
        if ($supervisorId) {
            return self::membros($empresa, $supervisorId);
        }

        return Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('user_type', UserType::PROMOTOR)
            ->orderBy('nome')
            ->get();
    }
}
