<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Ainda está lá?" (docs/83): um registro que volta a ser conferido nas próximas visitas à loja,
 * por qualquer promotor, até alguém responder "Acabou", vencer o prazo ou vir um registro novo do
 * mesmo item. Regras em App\Support\Acompanhamentos.
 */
class AcompanhamentoRegistro extends Model
{
    use BelongsToEmpresa, HasUuid;

    public const ABERTO = 'ABERTO';

    public const ENCERRADO = 'ENCERRADO';

    public const EXPIRADO = 'EXPIRADO';

    public const SUBSTITUIDO = 'SUBSTITUIDO';

    public const ENCERRADO_PELO_GESTOR = 'ENCERRADO_PELO_GESTOR';

    // Registro original cancelado: some pra todo mundo (docs/83 §6).
    public const CANCELADO = 'CANCELADO';

    protected $table = 'acompanhamentos_registro';

    protected $fillable = [
        'empresa_id', 'tipo_registro_id', 'ponto_venda_id', 'registro_origem_id', 'produto_auditoria_id', 'tipo_vinculo',
        'secao_id', 'departamento_id', 'marca_id', 'situacao', 'ultima_resposta_em', 'proxima_verificacao_em',
        'encerrado_em', 'encerrado_por_id', 'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'ultima_resposta_em' => 'datetime',
            'proxima_verificacao_em' => 'date',
            'encerrado_em' => 'datetime',
            'expira_em' => 'date',
        ];
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class);
    }

    public function registroOrigem(): BelongsTo
    {
        return $this->belongsTo(VisitaRegistro::class, 'registro_origem_id');
    }

    public function encerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'encerrado_por_id');
    }

    public function respostas(): HasMany
    {
        return $this->hasMany(AcompanhamentoResposta::class, 'acompanhamento_id');
    }

    /** Respostas da última coleta: o registro mais recente gerado num "Continua" com formulário. */
    public function ultimaColeta(): ?array
    {
        return VisitaRegistro::withoutGlobalScopes()
            ->where('acompanhamento_id', $this->id)
            ->where('tipo_registro_id', $this->tipo_registro_id)
            ->whereNull('cancelado_em')
            ->latest('created_at')
            ->value('valores_campos');
    }
}
