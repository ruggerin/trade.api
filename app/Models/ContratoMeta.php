<?php

namespace App\Models;

use App\Enums\FontePagamentoMeta;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta de contrapartida comercial (verba de trade marketing) negociada dentro de um Contrato —
 * ver docs/09-CONTRATO-METAS.md. Não é BelongsToEmpresa: herda o isolamento de tenant via
 * `contrato_id` (mesmo padrão de CampanhaItem/CentroCustoItem).
 */
class ContratoMeta extends Model
{
    use HasUuid;

    protected $table = 'contrato_metas';

    protected $fillable = [
        'contrato_id',
        'marca_id',
        'descricao',
        'valor_investimento',
        'meta_valor',
        'periodo_inicio',
        'periodo_fim',
        'fonte_pagamento',
        'percentual_industria',
        'resultado_apurado',
        'apurado_em',
        'apurado_por_usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_investimento' => 'decimal:2',
            'meta_valor' => 'decimal:2',
            'periodo_inicio' => 'datetime',
            'periodo_fim' => 'datetime',
            'fonte_pagamento' => FontePagamentoMeta::class,
            'percentual_industria' => 'decimal:2',
            'resultado_apurado' => 'decimal:2',
            'apurado_em' => 'datetime',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class, 'marca_id');
    }

    public function apuradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'apurado_por_usuario_id');
    }

    /**
     * Bloco de resumo computado — ver docs/09-CONTRATO-METAS.md §4. Nunca persistido: sempre
     * recalculado a partir dos campos negociados + resultado apurado (quando existir), mesmo
     * espírito de CentroCusto::resumoCusto.
     */
    public function resumo(): array
    {
        $valorInvestimento = (float) $this->valor_investimento;
        $percentualIndustria = $this->percentual_industria !== null ? (float) $this->percentual_industria : null;

        $valorInvestimentoIndustria = match ($this->fonte_pagamento) {
            FontePagamentoMeta::INDUSTRIA => $valorInvestimento,
            FontePagamentoMeta::COMPARTILHADO => $valorInvestimento * ($percentualIndustria ?? 0) / 100,
            FontePagamentoMeta::EMPRESA => 0.0,
        };
        $valorInvestimentoEmpresa = $valorInvestimento - $valorInvestimentoIndustria;

        $resultadoApurado = $this->resultado_apurado !== null ? (float) $this->resultado_apurado : null;
        $metaValor = (float) $this->meta_valor;

        $statusApuracao = $resultadoApurado === null
            ? 'AGUARDANDO'
            : ($resultadoApurado >= $metaValor ? 'ATINGIDA' : 'NAO_ATINGIDA');

        $percentualAtingido = $resultadoApurado === null || $metaValor == 0.0
            ? null
            : ($resultadoApurado / $metaValor) * 100;

        // "Quantas vezes voltou" — ex.: investiu 1.000, apurou 10.000 → 10x. Ver decisão em
        // docs/09-CONTRATO-METAS.md §7 (múltiplo, não percentual).
        $retornoSobreInvestimento = $resultadoApurado === null || $valorInvestimento == 0.0
            ? null
            : $resultadoApurado / $valorInvestimento;

        return [
            'valor_investimento_industria' => round($valorInvestimentoIndustria, 2),
            'valor_investimento_empresa' => round($valorInvestimentoEmpresa, 2),
            'status_apuracao' => $statusApuracao,
            'percentual_atingido' => $percentualAtingido !== null ? round($percentualAtingido, 2) : null,
            'retorno_sobre_investimento' => $retornoSobreInvestimento !== null ? round($retornoSobreInvestimento, 2) : null,
        ];
    }
}
