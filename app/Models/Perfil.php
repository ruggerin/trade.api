<?php

namespace App\Models;

use App\Enums\Permissao;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RBAC por empresa — ver docs/01-MODELO-DE-DADOS.md e App\Enums\Permissao. `permissoes` é um
 * array de valores de Permissao (jsonb), não uma tabela pivot: catálogo pequeno e fixo, não
 * precisa de query relacional.
 */
class Perfil extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    // Pluralização automática do Eloquent daria "perfils" (regra em inglês) — a tabela é
    // "perfis" (português correto), precisa declarar explícito.
    protected $table = 'perfis';

    protected $fillable = ['empresa_id', 'nome', 'descricao', 'permissoes', 'ativo'];

    protected function casts(): array
    {
        return [
            'permissoes' => 'array',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function tem(Permissao $permissao): bool
    {
        return $this->ativo && in_array($permissao->value, $this->permissoes ?? [], true);
    }
}
