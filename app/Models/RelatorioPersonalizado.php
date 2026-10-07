<?php

namespace App\Models;

use App\Enums\UserType;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Relatório do gerador — docs/60-GERADOR-DE-RELATORIOS.md §4. A `definicao` só contém chaves do
 * catálogo da entidade (App\Relatorios\Entidades\*), nunca SQL nem coluna crua.
 */
class RelatorioPersonalizado extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'relatorios_personalizados';

    protected $fillable = [
        'empresa_id',
        'usuario_id',
        'nome',
        'descricao',
        'entidade',
        'definicao',
        'compartilhado',
        'padrao',
        'chave',
        'versao',
        'fixado_empresa',
        'ordem_menu',
    ];

    protected function casts(): array
    {
        return [
            'definicao' => 'array',
            'compartilhado' => 'boolean',
            'padrao' => 'boolean',
            'versao' => 'integer',
            'fixado_empresa' => 'boolean',
            'ordem_menu' => 'integer',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Quem fixou este relatório no próprio menu (docs/63 §1.7, alcance "meu"). */
    public function fixadoPor(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'relatorios_fixados_usuario', 'relatorio_personalizado_id', 'usuario_id')
            ->withPivot('ordem');
    }

    /** Padrão do sistema, compartilhados da empresa e os próprios do usuário. */
    public function scopeVisivelPara(Builder $query, Usuario $usuario): Builder
    {
        // Colunas qualificadas: o escopo também roda sobre o join dos fixados (docs/63 §1.7).
        return $query->where(fn (Builder $q) => $q
            ->where('relatorios_personalizados.padrao', true)
            ->orWhere('relatorios_personalizados.compartilhado', true)
            ->orWhere('relatorios_personalizados.usuario_id', $usuario->id));
    }

    /** Padrão nunca é editável (só duplicável); o do cliente, pelo criador ou por um ADMIN. */
    public function editavelPor(Usuario $usuario): bool
    {
        if ($this->padrao) {
            return false;
        }

        return $this->usuario_id === $usuario->id || $usuario->user_type === UserType::ADMIN;
    }
}
