<?php

namespace App\Models;

use App\Enums\OrigemOrdemServico;
use App\Enums\PrioridadeVisita;
use App\Enums\StatusOrdemServico;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Compromisso de visita que um GESTOR/ADMIN direciona a um promotor (ou deixa em fila aberta,
 * usuario_id null) — separado de `Visita` de propósito: uma OS pode expirar/ser cancelada sem
 * nunca virar uma execução real. Ver docs/07-ORDEM-DE-SERVICO.md.
 */
class OrdemServico extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'ordens_servico';

    protected $fillable = [
        'empresa_id',
        'ponto_venda_id',
        'usuario_id',
        'origem',
        'campanha_id',
        'direcionamento_id',
        'tipo_visita_id',
        'objetivo_visita_id',
        'agenda_visita_id',
        'contrato_id',
        'prioridade',
        'horario_previsto',
        'obrigatoria',
        'prazo_inicio',
        'prazo_fim',
        'prazo_inicio_proposto',
        'prazo_fim_proposto',
        'status',
        'visita_id',
        'observacao',
        'motivo_rejeicao',
        'cancelada_em',
        'cancelada_por_id',
        'motivo_cancelamento_id',
        'motivo_cancelamento_texto',
        'responsavel_nao_execucao',
        // Régua de XP (docs/88 §6): congelados quando a OS nasce.
        'classe_id',
        'xp_previsto',
        'duracao_prevista_minutos',
    ];

    protected function casts(): array
    {
        return [
            'origem' => OrigemOrdemServico::class,
            'status' => StatusOrdemServico::class,
            'prioridade' => PrioridadeVisita::class,
            'obrigatoria' => 'boolean',
            'prazo_inicio' => 'datetime',
            'prazo_fim' => 'datetime',
            'prazo_inicio_proposto' => 'datetime',
            'prazo_fim_proposto' => 'datetime',
            'cancelada_em' => 'datetime',
            'xp_previsto' => 'integer',
            'duracao_prevista_minutos' => 'integer',
        ];
    }

    /**
     * Congela a classe da loja, o XP possível e a duração prevista no momento em que a OS nasce
     * (docs/88 §6) — vale pra toda origem (agenda, campanha, direcionamento, contrato, manual,
     * promotor). Mudar a classe da loja depois não reescreve o passado. Sem classe cadastrada,
     * fica nulo e a régua ignora a OS.
     */
    protected static function booted(): void
    {
        static::creating(function (self $os): void {
            if ($os->xp_previsto !== null || ! $os->ponto_venda_id) {
                return;
            }
            $pontoVenda = PontoVenda::withoutGlobalScopes()->find($os->ponto_venda_id);
            $classe = $pontoVenda ? ClassePontoVenda::daLoja($pontoVenda) : null;
            if (! $classe) {
                return;
            }
            $os->classe_id = $classe->id;
            $os->xp_previsto = $classe->xp_base;
            $os->duracao_prevista_minutos ??= $classe->duracao_padrao_minutos;
        });
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(ClassePontoVenda::class, 'classe_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class, 'ponto_venda_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(CampanhaAuditoria::class, 'campanha_id');
    }

    public function direcionamento(): BelongsTo
    {
        return $this->belongsTo(Direcionamento::class);
    }

    /**
     * Formulários exigidos por esta OS específica — copiados de
     * `direcionamento_formularios` na hora da geração (origem DIRECIONAMENTO) ou preenchidos
     * direto pelo gestor numa OS manual avulsa (§7.2). `respondido_em` marca quando o promotor
     * criou o VisitaRegistro correspondente, ver VisitaRegistroController::store.
     */
    public function formularios(): BelongsToMany
    {
        return $this->belongsToMany(TipoRegistro::class, 'ordem_servico_formularios')
            ->withPivot(['obrigatorio', 'calcula_percentual_compliance', 'respondido_em'])
            ->withTimestamps();
    }

    public function tipoVisita(): BelongsTo
    {
        return $this->belongsTo(TipoVisita::class, 'tipo_visita_id');
    }

    public function objetivoVisita(): BelongsTo
    {
        return $this->belongsTo(ObjetivoVisita::class, 'objetivo_visita_id');
    }

    public function agendaVisita(): BelongsTo
    {
        return $this->belongsTo(AgendaVisita::class, 'agenda_visita_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class, 'visita_id');
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cancelada_por_id');
    }

    public function motivoCancelamento(): BelongsTo
    {
        return $this->belongsTo(MotivoNaoExecucao::class, 'motivo_cancelamento_id');
    }
}
