<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Enums\StatusVisita;
use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Http\Requests\VisitaRegistro\ResolverAlertaRequest;
use App\Http\Requests\VisitaRegistro\StoreVisitaRegistroRequest;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\DepartamentoAuditoria;
use App\Models\ImagemRegistro;
use App\Models\MarcaAuditoria;
use App\Models\MotivoResolucaoAlerta;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\AcompanhamentoRegistro;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Acompanhamentos;
use App\Support\ConcorrentesPorMarca;
use App\Support\FotosNomeadas;
use App\Support\CancelamentoRegistro;
use App\Support\HorarioDoCampo;
use Illuminate\Support\Carbon;
use App\Support\Xp\CalculoVisitaXp;
use App\Support\Xp\ParametrosXp;
use App\Support\Xp\ReguaXp;
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
        // Marca como complemento de seção/departamento ("Biscoito · Piraquê", docs/82 §5.2), só
        // quando o formulário libera o filtro de marca.
        if (in_array($dados['tipo_vinculo'] ?? null, [TipoItemCampanha::SECAO->value, TipoItemCampanha::DEPARTAMENTO->value], true)
            && ! empty($dados['marca_uuid'])
            && TipoRegistro::whereKey($tipoRegistroId)->value('filtra_marca')) {
            $marcaId = MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id');
        }

        // Concorrentes por marca e embalagem (docs/85): a marca (inclusive a cadastrada offline
        // pelo promotor, que nasce aqui "a aprovar") e o vínculo saem do formulário, não do app.
        $tipoRegistroModel = TipoRegistro::find($tipoRegistroId);
        $embalagem = [];
        if ($tipoRegistroModel?->porMarca()) {
            $marcaId = ConcorrentesPorMarca::resolverMarca($dados['marca_uuid'] ?? null, $dados['marca_nova_descricao'] ?? null, $request->user())?->id;
            $vinculo = ConcorrentesPorMarca::vinculo($tipoRegistroModel);
            $dados['tipo_vinculo'] = $vinculo['tipo_vinculo'];
            $secaoId = $vinculo['secao_id'];
            $departamentoId = $vinculo['departamento_id'];
            $embalagem = [
                'embalagem_quantidade' => $dados['embalagem_quantidade'] ?? null,
                'embalagem_unidade' => $dados['embalagem_unidade'] ?? null,
            ];
        }

        // Hora do campo (docs/51 §3.5): o registro feito offline mantém a hora em que foi salvo no
        // celular — é o created_at que o feed, a galeria e os relatórios usam. `new` + save (em vez
        // de create) porque o Eloquent respeita um created_at já preenchido.
        $criadoEm = HorarioDoCampo::resolver($dados['criado_em'] ?? null, $visita->inicio_data);

        // Pergunta FOTO não tem resposta em valores_campos (docs/98): as fotos vão pra pivô.
        $valoresCampos = $dados['valores_campos'] ?? null;
        if (is_array($valoresCampos) && $tipoRegistroModel) {
            foreach ($tipoRegistroModel->campos->where('tipo_campo', TipoCampoRegistro::FOTO) as $campoFoto) {
                unset($valoresCampos[$campoFoto->chave]);
            }
        }

        $registro = new VisitaRegistro([
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
            'valores_campos' => $valoresCampos ?: null,
            'acompanhamento_id' => ! empty($dados['acompanhamento_uuid'])
                ? AcompanhamentoRegistro::where('uuid', $dados['acompanhamento_uuid'])->value('id')
                : null,
            ...$embalagem,
        ]);
        // Avaliação do gestor (doc 33 / docs/88 §10.1): com o recurso ligado, formulário marcado
        // nasce aguardando avaliação.
        if (ParametrosXp::avaliacaoHabilitada($visita->empresa()->withoutGlobalScopes()->first())
            && TipoRegistro::withoutGlobalScopes()->whereKey($tipoRegistroId)->value('exige_avaliacao')) {
            $registro->avaliacao_status = CalculoVisitaXp::STATUS_PENDENTE;
        }
        $registro->created_at = $criadoEm;
        $registro->recebido_em = now();
        $registro->save();

        // Marca o formulário como respondido na Ordem de Serviço desta visita, se houver uma —
        // é a peça que dá o controle de progresso "N expedidos, M preenchidos", ver
        // docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §3. `whereNull` evita sobrescrever um
        // respondido_em já preenchido por uma resposta anterior do mesmo formulário.
        if ($visita->ordem_servico_id) {
            DB::table('ordem_servico_formularios')
                ->where('ordem_servico_id', $visita->ordem_servico_id)
                ->where('tipo_registro_id', $tipoRegistroId)
                ->whereNull('respondido_em')
                ->update(['respondido_em' => $criadoEm, 'updated_at' => now()]);
        }

        // "Ainda está lá?" (docs/83): formulário com acompanhamento e gatilho satisfeito abre (e
        // substitui o anterior do mesmo item na loja).
        $registro->setRelation('visita', $visita);
        Acompanhamentos::abrirSeAplica($registro);

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

        // Fotos de visitas anteriores nesta loja numa pergunta FOTO (docs/98 §9.1) — já validadas
        // contra FotosNomeadas::historicoDaLoja no request.
        if (! empty($dados['imagens_historico'])) {
            $porUuid = FotosNomeadas::historicoDaLoja($visita)
                ->whereIn('uuid', collect($dados['imagens_historico'])->pluck('uuid'))
                ->get()
                ->keyBy('uuid');

            foreach ($dados['imagens_historico'] as $item) {
                $imagem = $porUuid->get($item['uuid']);
                if ($imagem && ! $registro->imagens()->whereKey($imagem->id)->exists()) {
                    $registro->imagens()->attach($imagem->id, ['ordem' => $ordem++, 'campo_chave' => $item['campo_chave'], 'origem' => 'HISTORICO']);
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
     * Uma foto do registro, enviada depois dele (o app manda o registro com `imagens_a_enviar` e
     * as fotos em seguida, uma por vez, cada uma com a própria retentativa). Idempotente por
     * `idempotency_key` dentro da visita: reenviar depois de perder a resposta devolve a mesma
     * foto. Aceita visita já finalizada (o check-out pode ter sido forçado pelo gestor enquanto a
     * foto subia) — só a cancelada recusa.
     */
    public function adicionarImagem(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);
        abort_if($registro->visita_id !== $visita->id, 404);

        if ($visita->status === StatusVisita::CANCELADA) {
            return response()->json(['message' => 'Esta visita foi cancelada, não é possível anexar fotos.'], 422);
        }

        $dados = $request->validate([
            'imagem' => ['required', 'file', 'image', 'max:8192'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            // Pergunta FOTO e origem (docs/98). Sem campo_chave = foto livre do registro.
            'campo_chave' => ['nullable', 'string', 'max:50'],
            'origem' => ['nullable', 'in:'.implode(',', FotosNomeadas::ORIGENS)],
            // Captura no aparelho (galeria Horus, docs/98 §9.1).
            'capturada_em' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'precisao_metros' => ['nullable', 'numeric', 'min:0'],
        ]);

        $campoChave = $dados['campo_chave'] ?? null;
        if ($campoChave !== null) {
            $campoFoto = $registro->tipoRegistro()->withoutGlobalScopes()->first()?->campos
                ->first(fn ($c) => $c->chave === $campoChave && $c->tipo_campo === TipoCampoRegistro::FOTO);
            if (! $campoFoto) {
                return response()->json(['message' => 'Pergunta de foto inválida para este formulário.', 'errors' => ['campo_chave' => ['Pergunta de foto inválida.']]], 422);
            }
        }

        $imagem = ! empty($dados['idempotency_key'])
            ? ImagemRegistro::where('visita_id', $visita->id)->where('idempotency_key', $dados['idempotency_key'])->first()
            : null;

        if (! $imagem) {
            $arquivo = $request->file('imagem');
            $nomeArquivo = Str::uuid()->toString().'.'.($arquivo->extension() ?: 'jpg');
            $arquivo->storeAs("visitas/{$visita->id}", $nomeArquivo, config('filesystems.default'));
            $latitude = isset($dados['latitude']) ? (float) $dados['latitude'] : null;
            $longitude = isset($dados['longitude']) ? (float) $dados['longitude'] : null;
            $imagem = ImagemRegistro::create([
                'visita_id' => $visita->id,
                'caminho' => "visitas/{$visita->id}/{$nomeArquivo}",
                'idempotency_key' => $dados['idempotency_key'] ?? null,
                // Relógio do aparelho nunca no futuro do servidor.
                'capturada_em' => isset($dados['capturada_em']) ? Carbon::parse($dados['capturada_em'])->min(now()) : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'precisao_metros' => isset($dados['precisao_metros']) ? (int) round((float) $dados['precisao_metros']) : null,
                'distancia_loja_metros' => FotosNomeadas::distanciaDaLoja($visita, $latitude, $longitude),
            ]);
        }

        if (! $registro->imagens()->whereKey($imagem->id)->exists()) {
            // Vai pro fim: as fotos chegam na ordem em que o promotor tirou.
            $ultima = DB::table('visita_registro_imagem')->where('visita_registro_id', $registro->id)->max('ordem');
            $registro->imagens()->attach($imagem->id, [
                'ordem' => $ultima === null ? 0 : $ultima + 1,
                'campo_chave' => $campoChave,
                'origem' => $dados['origem'] ?? ($campoChave !== null ? 'CAMERA' : null),
            ]);
        }

        $registro->setRelation('visita', $visita);
        $registro->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'imagens']);

        return response()->json(['registro' => new VisitaRegistroResource($registro)], 201);
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
        // Acompanhamento aberto por este registro some junto (docs/83 §6).
        Acompanhamentos::cancelarDoRegistro($registro);

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

        // Registro cancelado sai da régua de XP (docs/88).
        ReguaXp::recalcularVisita($visita, 'Registro cancelado.', $request->user()->id);

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
    public function resolverAlerta(ResolverAlertaRequest $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        if (! in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'Esta ação é só para ADMIN/GESTOR.');
        }

        abort_if($registro->visita_id !== $visita->id, 404);

        if ($registro->alerta_resolvido_em === null) {
            $dados = $request->validated();
            $registro->update([
                'alerta_resolvido_em' => now(),
                'alerta_resolvido_por_id' => $request->user()->id,
                'alerta_motivo_id' => isset($dados['motivo_uuid'])
                    ? MotivoResolucaoAlerta::where('uuid', $dados['motivo_uuid'])->value('id')
                    : null,
                'alerta_motivo_texto' => $dados['motivo_texto'] ?? null,
            ]);
            // Alerta resolvido como procedente: a informação virou ação (docs/88 §9.2).
            ReguaXp::recalcularVisita($visita);
        }

        $registro->setRelation('visita', $visita);
        $registro->load(['produtoAuditoria', 'tipoRegistro.campos', 'secao', 'departamento', 'marca', 'resolvidoPor', 'motivoResolucao', 'imagens']);

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
