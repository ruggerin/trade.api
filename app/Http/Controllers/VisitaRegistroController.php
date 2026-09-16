<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Enums\StatusVisita;
use App\Enums\TipoItemCampanha;
use App\Http\Requests\VisitaRegistro\StoreVisitaRegistroRequest;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\DepartamentoAuditoria;
use App\Models\ImagemRegistro;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\CancelamentoRegistro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $existente->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'imagens']);

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

        // Marca o formulário como respondido na Ordem de Serviço desta visita, se houver uma —
        // é a peça que dá o controle de progresso "N expedidos, M preenchidos", ver
        // docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §3. `whereNull` evita sobrescrever um
        // respondido_em já preenchido por uma resposta anterior do mesmo formulário.
        if ($visita->ordem_servico_id) {
            DB::table('ordem_servico_formularios')
                ->where('ordem_servico_id', $visita->ordem_servico_id)
                ->where('tipo_registro_id', $tipoRegistroId)
                ->whereNull('respondido_em')
                ->update(['respondido_em' => now(), 'updated_at' => now()]);
        }

        // Duas formas de anexar foto, combináveis — ver docs/21-EVIDENCIA-EM-FOTOS.md. `ordem`
        // segue a ordem em que cada uma aparece no envio: arquivos novos primeiro, depois as
        // já existentes vinculadas.
        $ordem = 0;

        if ($request->hasFile('imagens')) {
            foreach ($request->file('imagens') as $arquivo) {
                $nomeArquivo = Str::uuid()->toString().'.'.($arquivo->extension() ?: 'jpg');
                $arquivo->storeAs("visitas/{$visita->id}", $nomeArquivo, config('filesystems.default'));
                $imagem = ImagemRegistro::create([
                    'visita_id' => $visita->id,
                    'caminho' => "visitas/{$visita->id}/{$nomeArquivo}",
                ]);
                $registro->imagens()->attach($imagem->id, ['ordem' => $ordem++]);
            }
        }

        if (! empty($dados['imagens_existentes_uuids'])) {
            // Preserva a ordem submetida (não a ordem arbitrária da query) — busca tudo de uma
            // vez, depois percorre na ordem do array validado.
            $porUuid = ImagemRegistro::where('visita_id', $visita->id)
                ->whereIn('uuid', $dados['imagens_existentes_uuids'])
                ->get()
                ->keyBy('uuid');

            foreach ($dados['imagens_existentes_uuids'] as $uuid) {
                if ($imagem = $porUuid->get($uuid)) {
                    $registro->imagens()->attach($imagem->id, ['ordem' => $ordem++]);
                }
            }
        }

        // Evita 1 query extra só pra montar a url de cada imagem (ver VisitaRegistroResource).
        $registro->setRelation('visita', $visita);
        // Sem isso, os whenLoaded (produtoAuditoria, tipoRegistro etc.) sempre vêm null na
        // resposta do POST, mesmo quando os campos correspondentes foram enviados.
        $registro->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'imagens']);

        return response()->json([
            'registro' => new VisitaRegistroResource($registro),
        ], 201);
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

        // Reabre o formulário na Ordem de Serviço se esse cancelamento tirou a única resposta
        // válida — sem isso a OS ficava marcada como respondida (respondido_em) mesmo sem
        // nenhum registro válido. Só reabre se não sobrar outro registro não cancelado do mesmo
        // tipo nesta visita (ex.: reenvio). Ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §3.
        if ($visita->ordem_servico_id) {
            $aindaRespondido = VisitaRegistro::where('visita_id', $visita->id)
                ->where('tipo_registro_id', $registro->tipo_registro_id)
                ->whereNull('cancelado_em')
                ->exists();

            if (! $aindaRespondido) {
                DB::table('ordem_servico_formularios')
                    ->where('ordem_servico_id', $visita->ordem_servico_id)
                    ->where('tipo_registro_id', $registro->tipo_registro_id)
                    ->update(['respondido_em' => null, 'updated_at' => now()]);
            }
        }

        $registro->setRelation('visita', $visita);
        $registro->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'imagens']);

        return response()->json([
            'registro' => new VisitaRegistroResource($registro),
        ]);
    }

    /**
     * Marca um alerta (VisitaRegistro de um TipoRegistro com eh_alerta=true) como resolvido —
     * Painel de Atividades. Só ADMIN/GESTOR: é ação de supervisão sobre a visita de outra
     * pessoa, PROMOTOR não usa o painel. Sem "atribuir" a alguém — o primeiro que agir resolve,
     * igual um grupo de WhatsApp real. Idempotente: resolver de novo um já resolvido não é erro,
     * só mantém o estado atual — não é ação destrutiva que precise travar repetição. Ver
     * docs/17-PAINEL-ATIVIDADES.md.
     */
    public function resolverAlerta(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        if (! in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'Esta ação é só para ADMIN/GESTOR.');
        }

        abort_if($registro->visita_id !== $visita->id, 404);

        if ($registro->alerta_resolvido_em === null) {
            $registro->update(['alerta_resolvido_em' => now(), 'alerta_resolvido_por_id' => $request->user()->id]);
        }

        $registro->setRelation('visita', $visita);
        $registro->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'resolvidoPor', 'imagens']);

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
