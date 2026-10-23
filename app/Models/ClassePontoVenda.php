<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Classe de loja da régua de XP (docs/88 §4): quanto vale uma visita perfeita ali e quanto tempo
 * ela costuma levar. Loja sem classe usa a marcada como padrão. Quantas classes, a empresa decide.
 */
class ClassePontoVenda extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'classes_ponto_venda';

    protected $fillable = [
        'empresa_id', 'nome', 'icone', 'cor', 'xp_base', 'duracao_padrao_minutos', 'eh_padrao', 'ordem', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'xp_base' => 'integer',
            'duracao_padrao_minutos' => 'integer',
            'eh_padrao' => 'boolean',
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function pontosVenda(): HasMany
    {
        return $this->hasMany(PontoVenda::class, 'classe_id');
    }

    /**
     * Classes de exemplo do docs/88 §4.1 — criadas quando o SUPERADMIN liga a régua numa empresa
     * que ainda não tem nenhuma, pra ela calibrar em vez de começar do zero.
     */
    public static function semearExemplo(Empresa $empresa): void
    {
        if (self::withoutGlobalScopes()->where('empresa_id', $empresa->id)->exists()) {
            return;
        }
        $exemplo = [
            ['nome' => 'Chefão', 'icone' => 'crown', 'cor' => '#d97706', 'xp_base' => 500, 'duracao_padrao_minutos' => 150, 'eh_padrao' => false, 'ordem' => 1],
            ['nome' => 'Elite', 'icone' => 'sword-cross', 'cor' => '#6366f1', 'xp_base' => 150, 'duracao_padrao_minutos' => 45, 'eh_padrao' => false, 'ordem' => 2],
            ['nome' => 'Soldado', 'icone' => 'shield-outline', 'cor' => '#64748b', 'xp_base' => 50, 'duracao_padrao_minutos' => 15, 'eh_padrao' => true, 'ordem' => 3],
        ];
        foreach ($exemplo as $classe) {
            self::withoutGlobalScopes()->create($classe + ['empresa_id' => $empresa->id]);
        }
    }

    /** Classe que vale pra loja agora: a dela, senão a padrão ativa da empresa. */
    public static function daLoja(PontoVenda $pontoVenda): ?self
    {
        if ($pontoVenda->classe_id) {
            $classe = self::withoutGlobalScopes()->find($pontoVenda->classe_id);
            if ($classe?->ativo) {
                return $classe;
            }
        }

        return self::withoutGlobalScopes()
            ->where('empresa_id', $pontoVenda->empresa_id)
            ->where('eh_padrao', true)
            ->where('ativo', true)
            ->first();
    }
}
