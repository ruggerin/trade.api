<?php

namespace App\Models;

use App\Enums\StatusPlanoAcao;
use App\Enums\TipoItemCampanha;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tem uuid (usado na rota de imagem) mas não BelongsToEmpresa — herda o isolamento de Visita
 * via visita_id, ver docs/01-MODELO-DE-DADOS.md.
 */
class VisitaRegistro extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'visita_registros';

    protected $fillable = [
        'visita_id',
        'idempotency_key',
        'produto_auditoria_id',
        'tipo_registro_id',
        'tipo_vinculo',
        'secao_id',
        'departamento_id',
        'marca_id',
        'ruptura',
        'observacao',
        'valores_campos',
        // Só o próprio VisitaRegistroController::cancelar escreve aqui — soft, nunca hard
        // delete (mantém o registro e a foto como rastro histórico do que foi cancelado).
        'cancelado_em',
        // Resolução de alerta (Painel de Atividades) — mesmo raciocínio soft-state de
        // cancelado_em, só tem efeito visível quando a empresa liga o Parametro
        // ATIVIDADES_ALERTA_REQUER_RESOLUCAO. Ver VisitaRegistroController::resolverAlerta.
        'alerta_resolvido_em',
        'alerta_resolvido_por_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_vinculo' => TipoItemCampanha::class,
            'ruptura' => 'boolean',
            'valores_campos' => 'array',
            'cancelado_em' => 'datetime',
            'alerta_resolvido_em' => 'datetime',
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(VisitaRegistroComentario::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Contagem de comentários do registro (`comentarios_count`) e quantos são novos pra quem está
     * olhando (`comentarios_novos`: de outra pessoa, mais recentes que a última leitura desse
     * usuário — mesma regra do badge de ComentarioRegistroController::naoLidos). É o que deixa o
     * botão "3 comentários · 1 novo" aparecer sem abrir o feed, estilo Facebook.
     */
    public function scopeComContagemComentarios(Builder $query, int $usuarioId): Builder
    {
        return $query->withCount([
            'comentarios',
            'comentarios as comentarios_novos' => fn ($c) => $c
                ->where('usuario_id', '!=', $usuarioId)
                ->whereNotExists(fn ($l) => $l->select(DB::raw(1))
                    ->from('visita_registro_comentario_leituras as l')
                    ->whereColumn('l.visita_registro_id', 'visita_registro_comentarios.visita_registro_id')
                    ->where('l.usuario_id', $usuarioId)
                    ->whereColumn('l.lido_em', '>=', 'visita_registro_comentarios.created_at')),
        ]);
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'alerta_resolvido_por_id');
    }

    /**
     * Plano de Ação ABERTO/EM_ANDAMENTO nascido deste alerta — no máximo um por vez (índice
     * parcial em planos_acao). O card do Painel de Atividades usa pra trocar "Abrir Plano de
     * Ação" por "Ver plano". Ver docs/37-PLANOS-DE-ACAO.md §5.
     */
    public function planoAcaoAtivo(): HasOne
    {
        return $this->hasOne(PlanoAcao::class, 'origem_registro_id')
            ->whereIn('status', StatusPlanoAcao::ativos());
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }

    public function produtoAuditoria(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_auditoria_id');
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class);
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class);
    }

    /**
     * N:N — mesma foto pode evidenciar vários registros, um registro pode ter várias fotos. Ver
     * docs/21-EVIDENCIA-EM-FOTOS.md. Substitui o antigo campo imagem_path.
     */
    public function imagens(): BelongsToMany
    {
        return $this->belongsToMany(ImagemRegistro::class, 'visita_registro_imagem')
            ->withPivot('ordem')
            ->orderByPivot('ordem');
    }
}
