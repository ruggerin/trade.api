<?php

namespace App\Http\Controllers;

use App\Http\Requests\Planograma\StorePlanogramaRequest;
use App\Http\Requests\Planograma\UpdatePlanogramaRequest;
use App\Http\Resources\PlanogramaResource;
use App\Models\Empresa;
use App\Models\Planograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanogramaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $planogramas = Planograma::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — ver TipoRegistroController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'planogramas' => PlanogramaResource::collection($planogramas->items()),
            'meta' => [
                'current_page' => $planogramas->currentPage(),
                'last_page' => $planogramas->lastPage(),
                'per_page' => $planogramas->perPage(),
                'total' => $planogramas->total(),
            ],
        ]);
    }

    public function show(Planograma $planograma): JsonResponse
    {
        $planograma->load(['prateleiras.blocos.produtoAuditoria', 'empresa']);

        return response()->json([
            'planograma' => new PlanogramaResource($planograma),
        ]);
    }

    public function store(StorePlanogramaRequest $request): JsonResponse
    {
        $planograma = Planograma::create($request->validated());

        return response()->json([
            'planograma' => new PlanogramaResource($planograma),
        ], 201);
    }

    public function update(UpdatePlanogramaRequest $request, Planograma $planograma): JsonResponse
    {
        $planograma->update($request->validated());

        return response()->json([
            'planograma' => new PlanogramaResource($planograma),
        ]);
    }

    public function destroy(Planograma $planograma): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $planograma->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Capa visual — enviada de duas formas pelo admin: upload de uma foto real, ou um PNG
     * gerado no próprio navegador (screenshot da grade do editor) subindo por aqui igual a
     * um upload normal. O backend não distingue as duas origens. Mesmo padrão de
     * ContratoController::uploadArquivo.
     */
    public function uploadFotoCapa(Request $request, Planograma $planograma): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png'],
        ]);

        $arquivo = $request->file('foto');
        $nomeArquivo = "{$planograma->uuid}.".($arquivo->extension() ?: 'png');
        $arquivo->storeAs('planogramas', $nomeArquivo, config('filesystems.default'));
        $planograma->update(['foto_capa_path' => "planogramas/{$nomeArquivo}"]);

        return response()->json([
            'planograma' => new PlanogramaResource($planograma),
        ]);
    }

    /**
     * Leitura aberta a qualquer autenticado (fora do grupo catalogo.gerenciar) — o promotor
     * consome a capa no app mobile sem precisar de permissão de escrita nenhuma.
     */
    public function fotoCapa(Planograma $planograma): StreamedResponse
    {
        abort_if(! $planograma->foto_capa_path, 404);

        return Storage::disk(config('filesystems.default'))->response($planograma->foto_capa_path);
    }

    /**
     * Proxy de imagem externa — usado só pelo "Gerar automaticamente" do editor (ver
     * docs/22-PLANOGRAMA.md, decisão 8): a foto de cada produto vem de uma URL cadastrada
     * livremente no catálogo (`ProdutoAuditoria.imagem_url`), muitas vezes num host que não
     * libera CORS pra fetch entre domínios — sem isso, `html-to-image` não consegue embutir a
     * imagem no canvas e a geração automática falha pra qualquer catálogo real. O admin troca
     * temporariamente o `src` de cada `<img>` da grade por esta rota antes de capturar (mesma
     * origem, sem CORS) e desfaz depois.
     *
     * Protegido contra SSRF: só https, resolve o host e recusa IP privado/reservado/loopback
     * (bloqueia tentativa de sondar rede interna ou metadata de nuvem), redirect limitado a 3
     * saltos só-https (sem revalidar IP a cada salto — risco residual aceito, ver comentário
     * no corpo do método), só aceita resposta cujo Content-Type comece com "image/", timeout
     * curto e corpo limitado a 5MB.
     */
    public function proxyImagem(Request $request): Response
    {
        $request->validate([
            'url' => ['required', 'string', 'url:https', 'max:2048'],
        ]);

        $url = $request->string('url')->toString();
        $host = parse_url($url, PHP_URL_HOST);
        $ip = $host ? gethostbyname($host) : false;

        abort_unless(
            $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE),
            422,
            'URL de imagem inválida.',
        );

        try {
            // Catálogos de produto costumam redirecionar pra um bucket de object storage (ex.:
            // API própria → DigitalOcean Spaces) — bloquear redirect por completo quebrava o
            // caso normal. Permite até 3 saltos, só https (não deixa cair pra http no meio do
            // caminho) — não revalida IP a cada salto (o Guzzle não expõe esse gancho fácil),
            // aceito como risco residual dado que o endpoint já exige autenticação (não é
            // proxy público) e só aceita origem https.
            $resposta = Http::timeout(5)
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https'], 'strict' => true]])
                ->get($url);
        } catch (\Throwable) {
            abort(422, 'Não foi possível buscar a imagem.');
        }

        $contentType = $resposta->header('Content-Type');
        abort_unless(
            $resposta->successful() && $contentType && str_starts_with($contentType, 'image/') && strlen($resposta->body()) <= 5_242_880,
            422,
            'Não foi possível buscar a imagem.',
        );

        return response($resposta->body())->header('Content-Type', $contentType);
    }
}
