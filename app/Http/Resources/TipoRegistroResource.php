<?php

namespace App\Http\Resources;

use App\Models\TipoRegistro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TipoRegistro
 */
class TipoRegistroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            // Slug do Material Design Icons, sem prefixo — ver App\Support\IconeTipoRegistro.
            'icone' => $this->icone,
            // Sequência de exibição (admin e mobile listam por ela) — ver
            // TipoRegistroController::index/store/mover.
            'ordem' => $this->ordem,
            'exige_foto' => $this->exige_foto,
            'permite_vincular_catalogo' => $this->permite_vincular_catalogo,
            'acao_obrigatoria' => $this->acao_obrigatoria,
            'escopo_acao' => $this->escopo_acao?->value,
            'campanha_auditoria_uuid' => $this->whenLoaded('campanhaAuditoria', fn () => $this->campanhaAuditoria?->uuid),
            // Escopo LOJA_REDE (docs/40-ACAO-OBRIGATORIA-LOJA-REDE.md): uuids pro mobile resolver a
            // Ação (loja OU rede do PDV; as duas vazias = todas), nomes pro admin mostrar os chips.
            // Lista predefinida (granularidade PRODUTO) — com ela, o promotor só vincula a estes.
            'produtos_predefinidos' => $this->whenLoaded('produtosPredefinidos', fn () => $this->produtosPredefinidos->map(fn ($p) => [
                'id' => $p->uuid,
                'descricao' => $p->descricao,
                'codigo_barras' => $p->codigo_barras,
                'codigo_externo' => $p->codigo_externo,
            ])->values()),
            'pontos_venda_uuids' => $this->whenLoaded('pontosVenda', fn () => $this->pontosVenda->pluck('uuid')->values()),
            'redes_lojas_uuids' => $this->whenLoaded('redesLojas', fn () => $this->redesLojas->pluck('uuid')->values()),
            'pontos_venda_escopo' => $this->whenLoaded('pontosVenda', fn () => $this->pontosVenda->map(fn ($p) => ['id' => $p->uuid, 'fantasia' => $p->fantasia])->values()),
            'redes_lojas_escopo' => $this->whenLoaded('redesLojas', fn () => $this->redesLojas->map(fn ($r) => ['id' => $r->uuid, 'descricao' => $r->descricao])->values()),
            // Escopo LINHA_AUSENTE (docs/80 §5.1): o recorte que não pode estar no Mix. A seção leva o
            // departamento junto — o app usa pra "item DEPARTAMENTO cobre a seção".
            'escopo_tipo_vinculo' => $this->escopo_tipo_vinculo?->value,
            'escopo_secao' => $this->whenLoaded('escopoSecao', fn () => $this->escopoSecao ? [
                'id' => $this->escopoSecao->uuid,
                'descricao' => $this->escopoSecao->descricao,
                'departamento_uuid' => $this->escopoSecao->departamento?->uuid,
            ] : null),
            'escopo_departamento' => $this->whenLoaded('escopoDepartamento', fn () => $this->escopoDepartamento ? [
                'id' => $this->escopoDepartamento->uuid, 'descricao' => $this->escopoDepartamento->descricao,
            ] : null),
            'escopo_marca' => $this->whenLoaded('escopoMarca', fn () => $this->escopoMarca ? [
                'id' => $this->escopoMarca->uuid, 'descricao' => $this->escopoMarca->descricao,
            ] : null),
            // Intervalo entre cobranças da Ação, em dias (docs/80 §5.2) — 0 = toda visita.
            'intervalo_dias' => $this->intervalo_dias ?? 0,
            // Filtro da Ação por ramo de atividade da loja (docs/80 §5.4) — vazio = todos.
            'ramos_atividade_uuids' => $this->whenLoaded('ramosAtividade', fn () => $this->ramosAtividade->pluck('uuid')->values()),
            'ramos_atividade_escopo' => $this->whenLoaded('ramosAtividade', fn () => $this->ramosAtividade->map(fn ($r) => ['id' => $r->uuid, 'descricao' => $r->descricao])->values()),
            // Ver App\Support\GranularidadeChecklist e docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md.
            'granularidade_padrao' => $this->granularidade_padrao?->value,
            // Lista guiada (PRODUTO): FIXA = produtos_predefinidos; MIX_LOJA = nossos produtos do Mix da loja.
            'lista_produtos_origem' => $this->lista_produtos_origem ?? 'FIXA',
            // Concorrentes por marca e embalagem (docs/85).
            'permite_marca_nova' => (bool) ($this->permite_marca_nova ?? true),
            'varias_embalagens' => (bool) ($this->varias_embalagens ?? true),
            'marcas_predefinidas' => $this->whenLoaded('marcasPredefinidas', fn () => $this->marcasPredefinidas->map(fn ($m) => [
                'id' => $m->uuid,
                'descricao' => $m->descricao,
                'a_aprovar' => $m->status_aprovacao === \App\Enums\StatusAprovacao::PENDENTE,
            ])->values()),
            // Promotor escolhe marca/departamento/seção no início do formulário — ver docs/79.
            'escolhe_marca' => $this->escolhe_marca,
            'escolhe_departamento' => $this->escolhe_departamento,
            'escolhe_secao' => $this->escolhe_secao,
            // docs/82: o que a lista "Onde é" mostra e o filtro de marca depois da seção/departamento.
            'lista_recorte' => $this->lista_recorte ?? TipoRegistro::LISTA_PRIMEIRO_DA_LOJA,
            'filtra_marca' => (bool) $this->filtra_marca,
            // "Ainda está lá?" (docs/83 §3.1).
            'acompanha' => (bool) $this->acompanha,
            'acompanha_gatilho_chave' => $this->acompanha_gatilho_chave,
            'acompanha_gatilho_valor' => $this->acompanha_gatilho_valor,
            'acompanha_intervalo_dias' => $this->acompanha_intervalo_dias ?? 0,
            'acompanha_rotulo_continua' => $this->acompanha_rotulo_continua,
            'acompanha_rotulo_acabou' => $this->acompanha_rotulo_acabou,
            'acompanha_ao_continuar' => $this->acompanha_ao_continuar ?? 'CONFIRMA',
            'acompanha_formulario_acabou' => $this->acompanha_formulario_acabou_id
                ? (fn ($f) => $f ? ['id' => $f->uuid, 'descricao' => $f->descricao] : null)($this->formularioAcabou)
                : null,
            'acompanha_prazo_dias' => $this->acompanha_prazo_dias,
            // Marca a coluna "Ruptura" da grade de coleta (Fase 2) — ver
            // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9.
            'eh_ruptura' => $this->eh_ruptura,
            // Dispara evento de alerta no Painel de Atividades — ver
            // docs/17-PAINEL-ATIVIDADES.md.
            'eh_alerta' => $this->eh_alerta,
            'xp_iniciativa' => (int) $this->xp_iniciativa,
            'xp_bonus_acao' => (int) $this->xp_bonus_acao,
            'xp_teto_por_visita' => $this->xp_teto_por_visita,
            'xp_intervalo_dias' => (int) $this->xp_intervalo_dias,
            'exige_avaliacao' => (bool) $this->exige_avaliacao,
            // Fase 3 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md (decisões 5 e 8).
            'usa_pontuacao' => $this->usa_pontuacao,
            'disponivel_registro_livre' => $this->disponivel_registro_livre,
            'excecoes_granularidade' => $this->whenLoaded(
                'excecoesGranularidade',
                fn () => $this->excecoesGranularidade->map(fn ($excecao) => [
                    'secao_uuid' => $excecao->secao?->uuid,
                    'secao_descricao' => $excecao->secao?->descricao,
                    'granularidade' => $excecao->granularidade->value,
                ]),
            ),
            'campos' => CampoTipoRegistroResource::collection($this->whenLoaded('campos')),
            // Só vem preenchido pra quem pede como SUPERADMIN — mesmo padrão de
            // DepartamentoAuditoriaResource.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
