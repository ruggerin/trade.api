<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Enums\StatusVisita;
use App\Enums\TipoItemCampanha;
use App\Http\Requests\VisitaRegistro\StoreVisitaRegistroRequest;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\DepartamentoAuditoria;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\CancelamentoRegistro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitaRegistroController extends Controller
{
    public function store(StoreVisitaRegistroRequest $request, Visita $visita): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);

        if ($visita->status !== StatusVisita::ABERTA) {
            return response()->json([
                'message' => 'Esta visita não está aberta, não é possível adicionar registros.',
            ], 422);
        }

        $dados = $request->validated();

        if (! empty($dados['idempotency_key'])) {
            // Reenvio do mesmo registro (ex.: app fechou entre o servidor confirmar e o celular
            // gravar a resposta) — devolve o registro já criado em vez de duplicar a foto. Ver
            // VisitaController::store (mesmo raciocínio pro check-in).
            $existente = VisitaRegistro::where('visita_id', $visita->id)
                ->where('idempotency_key', $dados['idempotency_key'])
                ->first();

            if ($existente) {
                $existente->setRelation('visita', $visita);
                $existente->load(['produtoAuditoria', 'tipoRegistro', 'secao', 'departamento', 'marca']);

                return response()->json([
                    'registro' => new VisitaRegistroResource($existente),
                ], 200);
            }
        }

        $tipoRegistroId = TipoRegistro::where('uuid', $dados['tipo_registro_uuid'])->value('id');

        $produtoAuditoriaId = ! empty($dados['produto_auditoria_uuid'])
            ? ProdutoAuditoria::where('uuid', $dados['produto_auditoria_uuid'])->value('id')
            : null;

        // Vínculo opcional a um recorte mais amplo do catálogo — mesmo discriminador de
        // CampanhaItem, ver StoreVisitaRegistroRequest.
        $secaoId = null;
        $departamentoId = null;
        $marcaId = null;
        if (! empty($dados['tipo_vinculo'])) {
            match (TipoItemCampanha::from($dados['tipo_vinculo'])) {
                TipoItemCampanha::SECAO => $secaoId = SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id'),
                TipoItemCampanha::DEPARTAMENTO => $departamentoId = DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id'),
                TipoItemCampanha::MARCA => $marcaId = MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id'),
                TipoItemCampanha::PRODUTO => null, // já resolvido acima via produto_auditoria_uuid
            };
        }

        $registro = VisitaRegistro::create([
            'visita_id' => $visita->id,
            'idempotency_key' => $dados['idempotency_key'] ?? null,
            'produto_auditoria_id' => $produtoAuditoriaId,
            'tipo_registro_id' => $tipoRegistroId,
            'tipo_vinculo' => $dados['tipo_vinculo'] ?? null,
            'secao_id' => $secaoId,
            'departamento_id' => $departamentoId,
            'marca_id' => $marcaId,
            'ruptura' => $dados['ruptura'] ?? false,
            'observacao' => $dados['observacao'] ?? null,
            'valores_campos' => $dados['valores_campos'] ?? null,
        ]);

        if ($request->hasFile('imagem')) {
            $arquivo = $request->file('imagem');
            $nomeArquivo = "{$registro->uuid}.".($arquivo->extension() ?: 'jpg');
            $arquivo->storeAs("visitas/{$visita->id}", $nomeArquivo, config('filesystems.default'));
            $registro->update(['imagem_path' => "visitas/{$visita->id}/{$nomeArquivo}"]);
        }

        // Evita 1 query extra só pra montar imagem_url (ver VisitaRegistroResource).
        $registro->setRelation('visita', $visita);
        // Sem isso, os whenLoaded (produtoAuditoria, tipoRegistro etc.) sempre vêm null na
        // resposta do POST, mesmo quando os campos correspondentes foram enviados.
        $registro->load(['produtoAuditoria', 'tipoRegistro', 'secao', 'departamento', 'marca']);

        return response()->json([
            'registro' => new VisitaRegistroResource($registro),
        ], 201);
    }

    public function imagem(Request $request, Visita $visita, VisitaRegistro $registro): StreamedResponse
    {
        $this->autorizarAcesso($request, $visita);

        // VisitaRegistro não é tenant-aware por conta própria (herda de Visita) — confirma
        // manualmente que o registro pertence mesmo à visita da rota antes de servir o arquivo.
        abort_if($registro->visita_id !== $visita->id, 404);
        abort_if(! $registro->imagem_path, 404);

        return Storage::disk(config('filesystems.default'))->response($registro->imagem_path);
    }

    /**
     * Cancela (soft — nunca apaga) um registro já feito, com confirmação do lado do cliente
     * antes de chamar aqui. PROMOTOR só cancela registro da própria visita, e só se a empresa
     * permitir (`REGISTRO_CANCELAMENTO_PERMITIDO`, ver App\Support\CancelamentoRegistro);
     * ADMIN/GESTOR sempre podem, mesmo raciocínio de EnsurePermissao (o parâmetro é pensado pro
     * autosserviço do promotor, não trava quem já gerencia a empresa).
     */
    public function cancelar(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);

        abort_if($registro->visita_id !== $visita->id, 404);

        if ($registro->cancelado_em !== null) {
            return response()->json(['message' => 'Este registro já foi cancelado.'], 422);
        }

        $usuario = $request->user();
        if ($usuario->user_type === UserType::PROMOTOR && ! CancelamentoRegistro::permitidoParaPromotor($usuario->empresa)) {
            abort(403, 'Cancelamento de registro não está habilitado para promotores.');
        }

        $registro->update(['cancelado_em' => now()]);
        $registro->setRelation('visita', $visita);
        $registro->load(['produtoAuditoria', 'tipoRegistro', 'secao', 'departamento', 'marca']);

        return response()->json([
            'registro' => new VisitaRegistroResource($registro),
        ]);
    }

    private function autorizarAcesso(Request $request, Visita $visita): void
    {
        if ($request->user()->user_type === UserType::PROMOTOR && $visita->usuario_id !== $request->user()->id) {
            abort(403, 'Você não tem acesso a esta visita.');
        }
    }
}
