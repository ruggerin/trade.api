<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrdemServico
 */
class OrdemServicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            // endereco/bairro presentes pra a tela Agenda do mobile mostrar o endereço em cada
            // card sem precisar cruzar com a lista de PDV cacheada — ver
            // docs/13-AGENDA-MOBILE-E-AUTONOMIA.md §6.1.
            'ponto_venda' => $this->whenLoaded(
                'pontoVenda',
                fn () => [
                    'id' => $this->pontoVenda->uuid,
                    'fantasia' => $this->pontoVenda->fantasia,
                    'endereco' => $this->pontoVenda->endereco,
                    'bairro' => $this->pontoVenda->bairro,
                ],
            ),
            // null = fila aberta, qualquer promotor da empresa pode atender.
            'usuario' => $this->whenLoaded(
                'usuario',
                fn () => $this->usuario ? ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome] : null,
            ),
            'origem' => $this->origem,
            'campanha' => $this->whenLoaded(
                'campanha',
                fn () => $this->campanha ? ['id' => $this->campanha->uuid, 'descricao' => $this->campanha->descricao] : null,
            ),
            // tipo/prioridade/horário valem pra qualquer origem, não só AGENDA — ver
            // docs/10-AGENDA-VISITA.md §3.3.
            'tipo_visita' => $this->whenLoaded(
                'tipoVisita',
                fn () => $this->tipoVisita ? new TipoVisitaResource($this->tipoVisita) : null,
            ),
            // Motivo de negócio do compromisso — ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
            'objetivo_visita' => $this->whenLoaded(
                'objetivoVisita',
                fn () => $this->objetivoVisita ? new ObjetivoVisitaResource($this->objetivoVisita) : null,
            ),
            'agenda_visita' => $this->whenLoaded(
                'agendaVisita',
                fn () => $this->agendaVisita ? ['id' => $this->agendaVisita->uuid] : null,
            ),
            // Presente só em origem CONTRATO — ver App\Console\Commands\GerarOrdensServicoPorContrato.
            'contrato' => $this->whenLoaded(
                'contrato',
                fn () => $this->contrato ? ['id' => $this->contrato->uuid, 'tipo' => $this->contrato->tipo] : null,
            ),
            'prioridade' => $this->prioridade,
            // Postgres devolve TIME com segundos ("14:30:00") — corta pra "HH:mm", mesmo formato
            // aceito na validação de entrada (date_format:H:i).
            'horario_previsto' => $this->horario_previsto ? substr($this->horario_previsto, 0, 5) : null,
            'obrigatoria' => $this->obrigatoria,
            'prazo_inicio' => $this->prazo_inicio,
            'prazo_fim' => $this->prazo_fim,
            // Só presentes durante REAGENDAMENTO_SOLICITADO — o prazo oficial acima continua
            // intacto até o gestor decidir. Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
            'prazo_inicio_proposto' => $this->prazo_inicio_proposto,
            'prazo_fim_proposto' => $this->prazo_fim_proposto,
            'status' => $this->status,
            'visita' => $this->whenLoaded(
                'visita',
                fn () => $this->visita ? ['id' => $this->visita->uuid] : null,
            ),
            'observacao' => $this->observacao,
            // Preenchido só quando o gestor rejeitou a solicitação mais recente — ver
            // docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
            'motivo_rejeicao' => $this->motivo_rejeicao,
            // SUPERADMIN não usa esta tela hoje (sem rota dele aqui), mas mantém o padrão do
            // resto do catálogo pra facilitar se um dia precisar de visão cross-empresa.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
