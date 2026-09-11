<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de custo reutilizável atribuível a um ou mais promotores (Usuario.centro_custo_id) —
 * não é o holerite de uma pessoa específica. Ver docs/08-CENTRO-DE-CUSTO.md pra regra de
 * cálculo completa (nunca persistida, sempre computada a partir dos itens + quantidade de
 * promotores vinculados no momento da leitura).
 */
class CentroCusto extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'centros_custo';

    protected $fillable = [
        'empresa_id',
        'descricao',
        'carga_horaria_semanal',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'carga_horaria_semanal' => 'decimal:2',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CentroCustoItem::class, 'centro_custo_id')->orderBy('ordem');
    }

    public function promotores(): HasMany
    {
        return $this->hasMany(Usuario::class, 'centro_custo_id');
    }

    /**
     * Bloco de resumo computado — ver docs/08-CENTRO-DE-CUSTO.md §4. Nunca persistido: sempre
     * recalculado a partir dos itens e da contagem atual de promotores vinculados, pra nunca
     * ficar desatualizado quando alguém edita um item ou muda quem está vinculado.
     */
    public function resumoCusto(): array
    {
        $itens = $this->relationLoaded('itens') ? $this->itens : $this->itens()->get();

        $custoIndividualMensal = (float) $itens
            ->where('categoria', \App\Enums\CategoriaCentroCustoItem::INDIVIDUAL)
            ->sum('valor_mensal');
        $custoGeralMensal = (float) $itens
            ->where('categoria', \App\Enums\CategoriaCentroCustoItem::GERAL)
            ->sum('valor_mensal');

        // Usa a contagem pré-carregada por withCount() quando disponível (CentroCustoController
        // ::index — evita 1 query por linha da listagem); cai pra query direta nos outros casos
        // (store/update, que lidam com um único registro por vez).
        $qtdPromotoresAtivos = $this->promotores_count ?? $this->promotores()->where('ativo', true)->count();

        // Sem promotor vinculado ainda: não divide (mostra o custo geral cheio) em vez de
        // estourar divisão por zero ou esconder o número — decisão registrada em
        // docs/08-CENTRO-DE-CUSTO.md §4/§7.
        $custoGeralPorPromotor = $qtdPromotoresAtivos > 0
            ? $custoGeralMensal / $qtdPromotoresAtivos
            : $custoGeralMensal;

        $custoTotalMensalPromotor = $custoIndividualMensal + $custoGeralPorPromotor;

        // Média contábil de semanas/mês (52/12) — não "vezes 4", que fecharia o ano em só 48
        // semanas.
        $horasMensais = (float) $this->carga_horaria_semanal * (52 / 12);

        return [
            'qtd_promotores_ativos' => $qtdPromotoresAtivos,
            'custo_individual_mensal' => round($custoIndividualMensal, 2),
            'custo_geral_mensal' => round($custoGeralMensal, 2),
            'custo_geral_por_promotor' => round($custoGeralPorPromotor, 2),
            'custo_total_mensal_promotor' => round($custoTotalMensalPromotor, 2),
            'horas_mensais' => round($horasMensais, 2),
            'custo_por_hora' => $horasMensais > 0 ? round($custoTotalMensalPromotor / $horasMensais, 2) : 0.0,
        ];
    }
}
