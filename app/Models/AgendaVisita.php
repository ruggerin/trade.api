<?php

namespace App\Models;

use App\Enums\PrioridadeVisita;
use App\Enums\RecorrenciaAgendaVisita;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra recorrente (toda semana, num dia fixo) ou pontual (uma data específica) que define a
 * rotina de um promotor num PDV — o comando `ordens-servico:gerar-por-agenda` varre as regras
 * ativas todo dia e garante uma OrdemServico pendente pra cada uma que bate com hoje. Ver
 * docs/10-AGENDA-VISITA.md.
 */
class AgendaVisita extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'agendas_visita';

    protected $fillable = [
        'empresa_id',
        'ponto_venda_id',
        'usuario_id',
        'tipo_visita_id',
        'objetivo_visita_id',
        'prioridade',
        'recorrencia',
        'dia_semana',
        'data',
        'horario_previsto',
        'obrigatoria',
        'ativo',
        'observacao',
    ];

    protected function casts(): array
    {
        return [
            'prioridade' => PrioridadeVisita::class,
            'recorrencia' => RecorrenciaAgendaVisita::class,
            'dia_semana' => 'integer',
            'data' => 'date',
            'obrigatoria' => 'boolean',
            'ativo' => 'boolean',
        ];
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

    public function tipoVisita(): BelongsTo
    {
        return $this->belongsTo(TipoVisita::class, 'tipo_visita_id');
    }

    public function objetivoVisita(): BelongsTo
    {
        return $this->belongsTo(ObjetivoVisita::class, 'objetivo_visita_id');
    }
}
