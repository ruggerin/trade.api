<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\VisitaRegistroComentario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Feedback em registros de visita — docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §3. Feed cronológico
 * simples entre o promotor dono da visita e ADMIN/GESTOR (sem thread aninhada, sem push: o aviso é
 * um badge de não lidos consultado por polling). Visibilidade igual à do próprio registro.
 */
class ComentarioRegistroController extends Controller
{
    private const MAX_NAO_LIDOS_LISTADOS = 20;

    /** Lista o feed e marca como lido pra quem abriu — abrir o registro É o "visto". */
    public function index(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        $this->autorizar($request, $visita, $registro);

        $comentarios = $registro->comentarios()->with('usuario:id,uuid,nome,user_type')->get();

        DB::table('visita_registro_comentario_leituras')->upsert(
            [['visita_registro_id' => $registro->id, 'usuario_id' => $request->user()->id, 'lido_em' => now()]],
            ['visita_registro_id', 'usuario_id'],
            ['lido_em'],
        );

        return response()->json(['comentarios' => $comentarios->map(fn ($c) => $this->formatar($c, $request->user()))->values()]);
    }

    public function store(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        $this->autorizar($request, $visita, $registro);
        $dados = $request->validate(['texto' => ['required', 'string', 'max:2000']]);

        $comentario = $registro->comentarios()->create([
            'usuario_id' => $request->user()->id,
            'texto' => trim($dados['texto']),
        ]);
        $comentario->load('usuario:id,uuid,nome,user_type');

        // Quem acabou de escrever já viu tudo até aqui.
        DB::table('visita_registro_comentario_leituras')->upsert(
            [['visita_registro_id' => $registro->id, 'usuario_id' => $request->user()->id, 'lido_em' => now()]],
            ['visita_registro_id', 'usuario_id'],
            ['lido_em'],
        );

        return response()->json(['comentario' => $this->formatar($comentario, $request->user())], 201);
    }

    /**
     * Badge: comentários de OUTRA pessoa, num registro do meu alcance, mais novos que a minha última
     * leitura desse registro. Promotor → registros das próprias visitas; ADMIN/GESTOR → qualquer
     * registro da empresa (o escopo de empresa vem do global scope de Visita).
     */
    public function naoLidos(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $linhas = DB::table('visita_registro_comentarios as c')
            ->join('visita_registros as r', 'r.id', '=', 'c.visita_registro_id')
            ->join('visitas as v', 'v.id', '=', 'r.visita_id')
            ->join('usuarios as autor', 'autor.id', '=', 'c.usuario_id')
            ->join('tipos_registro as tr', 'tr.id', '=', 'r.tipo_registro_id')
            ->leftJoin('produtos_auditoria as p', 'p.id', '=', 'r.produto_auditoria_id')
            ->leftJoin('visita_registro_comentario_leituras as l', fn ($j) => $j
                ->on('l.visita_registro_id', '=', 'c.visita_registro_id')
                ->where('l.usuario_id', '=', $usuario->id))
            ->where('v.empresa_id', $usuario->empresa_id)
            ->where('c.usuario_id', '!=', $usuario->id)
            ->when($usuario->user_type === UserType::PROMOTOR, fn ($q) => $q->where('v.usuario_id', $usuario->id))
            ->where(fn ($q) => $q->whereNull('l.lido_em')->orWhereColumn('c.created_at', '>', 'l.lido_em'))
            ->orderByDesc('c.created_at')
            ->get([
                'c.uuid as comentario_uuid', 'c.texto', 'c.created_at', 'r.uuid as registro_uuid',
                'v.uuid as visita_uuid', 'v.ponto_venda_id', 'autor.nome as autor',
                'tr.descricao as tipo_registro_descricao', 'p.descricao as produto_descricao',
            ]);

        $pdvs = DB::table('pontos_venda')->whereIn('id', $linhas->pluck('ponto_venda_id')->unique())->pluck('fantasia', 'id');

        $porRegistro = $linhas->groupBy('registro_uuid')->map(function ($grupo) use ($pdvs) {
            $ultimo = $grupo->first();

            return [
                'registro_id' => $ultimo->registro_uuid,
                'visita_id' => $ultimo->visita_uuid,
                'ponto_venda' => $pdvs[$ultimo->ponto_venda_id] ?? null,
                // Produto quando o registro tem um vinculado, senão o tipo (mesma prioridade de
                // VisitaDetalheScreen.RegistroCard no mobile).
                'sobre' => $ultimo->produto_descricao ?? $ultimo->tipo_registro_descricao,
                'nao_lidos' => $grupo->count(),
                'ultimo' => ['autor' => $ultimo->autor, 'texto' => $ultimo->texto, 'em' => $ultimo->created_at],
            ];
        })->values();

        return response()->json([
            'total' => $linhas->count(),
            'registros' => $porRegistro->take(self::MAX_NAO_LIDOS_LISTADOS)->values(),
        ]);
    }

    private function autorizar(Request $request, Visita $visita, VisitaRegistro $registro): void
    {
        $usuario = $request->user();
        if ($usuario->user_type === UserType::PROMOTOR && $visita->usuario_id !== $usuario->id) {
            abort(403, 'Você não tem acesso a esta visita.');
        }
        abort_if($registro->visita_id !== $visita->id, 404);
    }

    private function formatar(VisitaRegistroComentario $comentario, Usuario $leitor): array
    {
        return [
            'id' => $comentario->uuid,
            'texto' => $comentario->texto,
            'criado_em' => $comentario->created_at,
            'autor' => [
                'id' => $comentario->usuario->uuid,
                'nome' => $comentario->usuario->nome,
                'tipo' => $comentario->usuario->user_type,
            ],
            'meu' => $comentario->usuario_id === $leitor->id,
        ];
    }
}
