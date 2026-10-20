<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Xp\ParametrosXp;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Empresa
 */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Convenção da API: "id" é sempre o uuid, o bigint interno nunca é exposto — ver
            // docs/02-API-BACKEND.md#convenção-de-identificadores-na-api.
            'id' => $this->uuid,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'cnpj' => $this->cnpj,
            'plano' => $this->plano,
            'limite_usuarios' => $this->limite_usuarios,
            'limite_pontos_venda' => $this->limite_pontos_venda,
            'limite_licencas' => $this->limite_licencas,
            // Módulo Pedido de Venda contratado (docs/38 §12) — o admin desabilita as permissões
            // pedidos_venda.* no Perfil e o mobile esconde a aba Pedidos quando false.
            'pedidos_venda_habilitado' => (bool) $this->pedidos_venda_habilitado,
            // Faturamento contratado no plano (docs/61) — sem ele o parâmetro FATURAMENTO_HABILITADO fica travado.
            'faturamento_contratado' => (bool) $this->faturamento_contratado,
            // Régua de XP (docs/88 §2.1) — recurso de plano. Com ele ligado, o que o promotor vê
            // (modo prévia, pendente, liga) vai junto pro app esconder ou mostrar sem chamada extra.
            'controle_xp_habilitado' => (bool) $this->controle_xp_habilitado,
            'xp' => $this->when((bool) $this->controle_xp_habilitado, fn () => [
                'visivel_promotor' => ParametrosXp::visivelPromotor($this->resource),
                'pendente_visivel_promotor' => ParametrosXp::pendenteVisivelPromotor($this->resource),
                'ranking_habilitado' => ParametrosXp::rankingHabilitado($this->resource),
            ]),
            // Avaliação de formulário pelo gestor (doc 33) — independe do XP.
            'avaliacao_formulario_habilitada' => ParametrosXp::avaliacaoHabilitada($this->resource),
            'ativo' => $this->ativo,
            // Fuso (IANA) que define o corte de "dia" da empresa (docs/50 §4.3).
            'fuso' => $this->fuso,
            // Só na lista/detalhe de empresas do SUPERADMIN (docs/52 §4.2).
            'adesao' => $this->when($this->resource->adesao !== null, fn () => $this->resource->adesao),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
