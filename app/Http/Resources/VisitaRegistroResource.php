<?php

namespace App\Http\Resources;

use App\Support\CalculadoraPontuacao;
use App\Support\FormatadorValoresCampos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\VisitaRegistro
 */
class VisitaRegistroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'tipo_registro' => $this->whenLoaded(
                'tipoRegistro',
                fn () => $this->tipoRegistro ? [
                    'id' => $this->tipoRegistro->uuid,
                    'descricao' => $this->tipoRegistro->descricao,
                    'icone' => $this->tipoRegistro->icone,
                ] : null,
            ),
            'produto_auditoria' => $this->whenLoaded(
                'produtoAuditoria',
                fn () => $this->produtoAuditoria ? [
                    'id' => $this->produtoAuditoria->uuid,
                    'descricao' => $this->produtoAuditoria->descricao,
                ] : null,
            ),
            // Vínculo opcional a um recorte mais amplo do catálogo — no máximo um destes três
            // vem preenchido, conforme `tipo_vinculo` (mesmo padrão de CampanhaItemResource).
            'tipo_vinculo' => $this->tipo_vinculo,
            'secao' => $this->whenLoaded(
                'secao',
                fn () => $this->secao ? ['id' => $this->secao->uuid, 'descricao' => $this->secao->descricao] : null,
            ),
            'departamento' => $this->whenLoaded(
                'departamento',
                fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null,
            ),
            'marca' => $this->whenLoaded(
                'marca',
                fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null,
            ),
            'ruptura' => $this->ruptura,
            'observacao' => $this->observacao,
            'valores_campos' => $this->valores_campos,
            // Mesmo dado de valores_campos, mas já com rótulo resolvido e valor formatado por
            // tipo_campo (BOOLEANO vira Sim/Não, SORTIMENTO resolve os uuids de produto pra
            // descrição) — ver App\Support\FormatadorValoresCampos. Pronto pra render direto,
            // sem o front precisar conhecer os tipos de campo.
            'campos_respondidos' => $this->whenLoaded(
                'tipoRegistro',
                fn () => $this->tipoRegistro
                    ? FormatadorValoresCampos::formatar($this->tipoRegistro, $this->valores_campos)
                    : [],
            ),
            // % de compliance do formulário — só quando tipo_registro.usa_pontuacao = true, ver
            // App\Support\CalculadoraPontuacao e decisão 5 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
            'pontuacao' => $this->whenLoaded(
                'tipoRegistro',
                fn () => $this->tipoRegistro ? CalculadoraPontuacao::calcular($this->tipoRegistro, $this->valores_campos) : null,
            ),
            // N:N — mesma foto pode evidenciar vários registros, um registro pode ter várias
            // fotos. Ver docs/21-EVIDENCIA-EM-FOTOS.md. Substitui o antigo imagem_url (string
            // única) — quem só precisa de uma foto usa imagens[0].
            'imagens' => $this->whenLoaded('imagens', fn () => $this->imagens->map(fn ($img) => [
                'id' => $img->uuid,
                'url' => url("/api/visitas/{$this->visita->uuid}/imagens/{$img->uuid}"),
            ])),
            // Uuid da visita dona — o lightbox de fotos do admin precisa dele pra abrir o feed de
            // comentários do registro (rota aninhada em /visitas/{visita}/registros/{registro}).
            'visita_id' => $this->whenLoaded('visita', fn () => $this->visita->uuid),
            // Só presentes quando quem carregou o registro usou scopeComContagemComentarios —
            // docs/28 §3, o "3 comentários · 1 novo" do botão de feedback.
            'comentarios_count' => $this->when($this->comentarios_count !== null, fn () => (int) $this->comentarios_count),
            'comentarios_novos' => $this->when($this->comentarios_novos !== null, fn () => (int) $this->comentarios_novos),
            // Soft — a linha continua existindo mesmo cancelada (rastro histórico). Ver
            // App\Support\CancelamentoRegistro.
            'cancelado_em' => $this->cancelado_em,
            // Resolução de alerta no Painel de Atividades — só relevante quando o
            // TipoRegistro tem eh_alerta=true. Ver VisitaRegistroController::resolverAlerta e
            // docs/17-PAINEL-ATIVIDADES.md.
            'alerta_resolvido_em' => $this->alerta_resolvido_em,
            'resolvido_por' => $this->whenLoaded(
                'resolvidoPor',
                fn () => $this->resolvidoPor ? ['id' => $this->resolvidoPor->uuid, 'nome' => $this->resolvidoPor->nome] : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
