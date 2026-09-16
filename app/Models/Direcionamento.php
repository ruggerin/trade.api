<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Molde que gera várias OrdemServico de uma vez — descrição + vigência + filtros multi-escolha
 * de quem/onde (promotor/loja/rede, todos opcionais) + lista de formulários exigidos. Não é o
 * que o promotor vê — o promotor vê as OrdemServico que ele gera (§4). Ver
 * docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md.
 */
class Direcionamento extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $fillable = [
        'empresa_id',
        'descricao',
        'vigencia_inicio',
        'vigencia_fim',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'datetime',
            'vigencia_fim' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Formulários exigidos — obrigatorio/calcula_percentual_compliance vivem no pivot, não no
     * TipoRegistro (docs/25 §2 decisão 9): o mesmo formulário pode ser obrigatório aqui e
     * opcional noutro Direcionamento.
     */
    public function formularios(): BelongsToMany
    {
        return $this->belongsToMany(TipoRegistro::class, 'direcionamento_formularios')
            ->withPivot(['obrigatorio', 'calcula_percentual_compliance'])
            ->withTimestamps();
    }

    public function pontosVenda(): BelongsToMany
    {
        return $this->belongsToMany(PontoVenda::class, 'direcionamento_pontos_venda');
    }

    public function redesLoja(): BelongsToMany
    {
        return $this->belongsToMany(RedeLoja::class, 'direcionamento_redes_loja');
    }

    public function promotores(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'direcionamento_promotores', 'direcionamento_id', 'usuario_id');
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class);
    }
}
