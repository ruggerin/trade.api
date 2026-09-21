<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\VisitaRegistroComentario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Painel de Atividades — feed único, cronológico, com tudo que rolou nas visitas do dia (todos
 * os promotores, todas as lojas): o substituto do grupo de WhatsApp que o gestor usa hoje. Ver
 * docs/17-PAINEL-ATIVIDADES.md.
 *
 * Não existe um "evento" unificado no banco — este endpoint junta duas fontes (Visita e
 * VisitaRegistro marcado como alerta) e mescla em memória, já que o volume esperado (uma
 * empresa, normalmente filtrado por "hoje") é pequeno. Paginado por página (não cursor de
 * verdade — mais simples numa fonte que já é uma mescla de duas tabelas diferentes), mesmo
 * `meta` de `GET /api/visitas`, consumido pelo admin via infinite scroll (ver
 * docs/19-PAINEL-ATIVIDADES.md).
 */
class AtividadeController extends Controller
{
    private const POR_PAGINA = 20;

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'O Painel de Atividades é só para ADMIN/GESTOR.');
        }

        $usuarioId = $request->filled('usuario_uuid')
            ? Usuario::where('uuid', $request->string('usuario_uuid'))->value('id')
            : null;
        $pontoVendaId = $request->filled('ponto_venda_uuid')
            ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id')
            : null;
        $tipoRegistroId = $request->filled('tipo_registro_uuid')
            ? TipoRegistro::where('uuid', $request->string('tipo_registro_uuid'))->value('id')
            : null;
        $apenasPendentes = $request->boolean('pendentes');

        // Filtro por tipo específico ou pela aba de pendências: só interessa o alerta em si,
        // sem o ruído de check-in/checkout da visita.
        $somenteAlertas = $request->filled('tipo_registro_uuid') || $apenasPendentes;

        $eventos = $this->eventosDeAlerta($request, $usuarioId, $pontoVendaId, $tipoRegistroId, $apenasPendentes);

        if (! $somenteAlertas) {
            $eventos = $eventos->concat($this->eventosDeVisita($request, $usuarioId, $pontoVendaId));
            // Resposta do promotor num feedback (docs/28 §3) — o admin descobre pelo feed.
            $eventos = $eventos->concat($this->eventosDeComentario($request, $usuarioId, $pontoVendaId));
        }

        $eventos = $eventos->sortByDesc('ocorrido_em')->values();

        $pagina = max(1, $request->integer('page', 1));
        $total = $eventos->count();
        $ultimaPagina = max(1, (int) ceil($total / self::POR_PAGINA));

        return response()->json([
            'eventos' => $eventos->forPage($pagina, self::POR_PAGINA)->values(),
            'meta' => [
                'current_page' => $pagina,
                'last_page' => $ultimaPagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
            ],
        ]);
    }

    private function eventosDeVisita(Request $request, ?int $usuarioId, ?int $pontoVendaId): Collection
    {
        $visitas = Visita::query()
            ->with([
                'pontoVenda', 'usuario',
                // Fotos coletadas na visita, pro mosaico do card de VISITA_FINALIZADA — mesma
                // regra de exclusão de cancelado_em das outras contagens desta query. Carrega as
                // mesmas relações de eventosDeAlerta() pra reaproveitar o VisitaRegistroResource
                // inteiro (tipo, produto/vínculo, observação) — a galeria de fotos do card
                // mostra essa informação junto de cada imagem, não só a foto pelada.
                'registros' => fn ($q) => $q->whereNull('cancelado_em')->whereHas('imagens')
                    ->comContagemComentarios($request->user()->id)
                    ->with(['tipoRegistro.campos', 'produtoAuditoria', 'secao', 'departamento', 'marca', 'imagens']),
            ])
            ->withCount([
                'registros as registros_count' => fn ($q) => $q->whereNull('cancelado_em'),
                'registros as rupturas_count' => fn ($q) => $q->whereNull('cancelado_em')->where('ruptura', true),
            ])
            ->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
            ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            ->when($request->filled('data_inicio'), fn ($q) => $q->whereDate('inicio_data', '>=', $request->string('data_inicio')))
            ->when($request->filled('data_fim'), fn ($q) => $q->whereDate('inicio_data', '<=', $request->string('data_fim')))
            ->get();

        $eventos = collect();

        foreach ($visitas as $visita) {
            $pontoVenda = $visita->pontoVenda ? ['id' => $visita->pontoVenda->uuid, 'fantasia' => $visita->pontoVenda->fantasia] : null;
            $usuario = $this->usuarioParaEvento($visita->usuario);

            $eventos->push([
                'tipo_evento' => 'VISITA_INICIADA',
                'ocorrido_em' => $visita->inicio_data,
                'visita' => ['id' => $visita->uuid],
                'ponto_venda' => $pontoVenda,
                'usuario' => $usuario,
                // GPS do check-in — pro card mostrar um mapinha, mesmo dado que já valida o
                // raio no backend (ver regra de negócio 1, docs/02-API-BACKEND.md).
                'localizacao' => [
                    'latitude' => (float) $visita->inicio_latitude,
                    'longitude' => (float) $visita->inicio_longitude,
                    'distancia_metros' => $visita->inicio_distancia_metros !== null
                        ? (int) round((float) $visita->inicio_distancia_metros)
                        : null,
                ],
            ]);

            if ($visita->fim_data !== null) {
                $eventos->push([
                    'tipo_evento' => 'VISITA_FINALIZADA',
                    'ocorrido_em' => $visita->fim_data,
                    'visita' => ['id' => $visita->uuid],
                    'ponto_venda' => $pontoVenda,
                    'usuario' => $usuario,
                    'resumo' => [
                        'registros' => $visita->registros_count,
                        'rupturas' => $visita->rupturas_count,
                    ],
                    // Mosaico de fotos coletadas na visita — ver eager load de 'registros'
                    // acima. setRelation('visita', ...) evita 1 query por registro só pra
                    // montar imagem_url (mesmo truque de VisitaController::show).
                    'imagens' => VisitaRegistroResource::collection(
                        $visita->registros->each(fn (VisitaRegistro $r) => $r->setRelation('visita', $visita)),
                    ),
                ]);
            }
        }

        return $eventos;
    }

    /** Comentários escritos por PROMOTOR em registros — o que o admin precisa ver sem procurar. */
    private function eventosDeComentario(Request $request, ?int $usuarioId, ?int $pontoVendaId): Collection
    {
        $comentarios = VisitaRegistroComentario::query()
            ->whereHas('usuario', fn ($q) => $q->where('user_type', UserType::PROMOTOR->value))
            ->whereHas('registro.visita', function ($q) use ($request, $usuarioId, $pontoVendaId) {
                $q->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
                    ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId));
            })
            ->when($request->filled('data_inicio'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('data_inicio')))
            ->when($request->filled('data_fim'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('data_fim')))
            ->with([
                'usuario',
                'registro' => fn ($q) => $q->comContagemComentarios($request->user()->id),
                'registro.visita.pontoVenda', 'registro.tipoRegistro', 'registro.produtoAuditoria',
            ])
            ->get();

        return $comentarios->map(fn (VisitaRegistroComentario $c) => [
            'tipo_evento' => 'COMENTARIO',
            'ocorrido_em' => $c->created_at,
            'visita' => ['id' => $c->registro->visita->uuid],
            'ponto_venda' => $c->registro->visita->pontoVenda
                ? ['id' => $c->registro->visita->pontoVenda->uuid, 'fantasia' => $c->registro->visita->pontoVenda->fantasia]
                : null,
            'usuario' => $this->usuarioParaEvento($c->usuario),
            'comentario' => [
                'id' => $c->uuid,
                'texto' => $c->texto,
                'registro_id' => $c->registro->uuid,
                'tipo_registro' => $c->registro->tipoRegistro?->descricao,
                'produto' => $c->registro->produtoAuditoria?->descricao,
                'comentarios_count' => (int) $c->registro->comentarios_count,
                'comentarios_novos' => (int) $c->registro->comentarios_novos,
            ],
        ]);
    }

    private function eventosDeAlerta(
        Request $request,
        ?int $usuarioId,
        ?int $pontoVendaId,
        ?int $tipoRegistroId,
        bool $apenasPendentes,
    ): Collection {
        $registros = VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->whereHas('visita', function ($q) use ($request, $usuarioId, $pontoVendaId) {
                $q->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
                    ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId));
            })
            ->when($request->filled('tipo_registro_uuid'), fn ($q) => $q->where('tipo_registro_id', $tipoRegistroId))
            ->when($apenasPendentes, fn ($q) => $q->whereNull('alerta_resolvido_em'))
            ->when($request->filled('data_inicio'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('data_inicio')))
            ->when($request->filled('data_fim'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('data_fim')))
            ->comContagemComentarios($request->user()->id)
            ->with([
                'visita.pontoVenda', 'visita.usuario', 'tipoRegistro.campos', 'produtoAuditoria',
                'secao', 'departamento', 'marca', 'resolvidoPor', 'imagens',
            ])
            ->get();

        return $registros->map(fn (VisitaRegistro $registro) => [
            'tipo_evento' => 'ALERTA',
            'ocorrido_em' => $registro->created_at,
            'visita' => ['id' => $registro->visita->uuid],
            'ponto_venda' => $registro->visita->pontoVenda
                ? ['id' => $registro->visita->pontoVenda->uuid, 'fantasia' => $registro->visita->pontoVenda->fantasia]
                : null,
            'usuario' => $this->usuarioParaEvento($registro->visita->usuario),
            'registro' => new VisitaRegistroResource($registro),
        ]);
    }

    /**
     * Mesmo shape enxuto do usuário em todo evento do feed — inclui foto_url (mesmo cálculo de
     * UsuarioResource) pra render de avatar no admin, estilo "grupo de WhatsApp".
     *
     * @return array{id: string, nome: string, foto_url: string|null}|null
     */
    private function usuarioParaEvento(?Usuario $usuario): ?array
    {
        if (! $usuario) {
            return null;
        }

        return [
            'id' => $usuario->uuid,
            'nome' => $usuario->nome,
            'foto_url' => $usuario->foto_path ? url("/api/usuarios/{$usuario->uuid}/foto") : null,
        ];
    }
}
