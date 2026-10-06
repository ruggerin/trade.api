<?php

namespace App\Http\Controllers;

use App\Enums\TipoDocumentoLegal;
use App\Http\Requests\Auth\AceitarDocumentosLegaisRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\AceiteDocumentoLegal;
use App\Models\DocumentoLegal;
use App\Models\Usuario;
use App\Support\DocumentosLegais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Termos de Uso e Política de Privacidade — docs/58-ACEITE-TERMOS-E-PRIVACIDADE.md §5.
 */
class DocumentoLegalController extends Controller
{
    /** Leitura pública (sem login) da versão vigente, ou de uma versão específica com `?versao=`. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $documento = $this->buscar($slug, $request->query('versao'));

        return response()->json([
            'tipo' => $documento->tipo->value,
            'titulo' => $documento->tipo->titulo(),
            'versao' => $documento->versao,
            'publicado_em' => $documento->publicado_em,
            'conteudo' => $documento->conteudo,
        ]);
    }

    /** Página HTML pública (rota web) — é a URL da Play Store e dos links do login (§5.2). */
    public function pagina(Request $request, string $slug): Response
    {
        $documento = $this->buscar($slug, $request->query('versao'));

        $ambiente = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $ambiente->addExtension(new CommonMarkCoreExtension());
        $ambiente->addExtension(new GithubFlavoredMarkdownExtension());

        return response(view('documento-legal', [
            'titulo' => $documento->tipo->titulo(),
            'versao' => $documento->versao,
            'conteudoHtml' => (new MarkdownConverter($ambiente))->convert($documento->conteudo)->getContent(),
        ]));
    }

    /** Registra o aceite do próprio usuário (§5.4). */
    public function aceitar(AceitarDocumentosLegaisRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validated();

        $documentos = DocumentosLegais::vigentesConferidos($dados['documentos']);
        if ($documentos === null) {
            return response()->json([
                'message' => 'Os documentos foram atualizados. Leia a versão atual antes de aceitar.',
                'documentos_pendentes' => DocumentosLegais::pendentes($usuario),
            ], 409);
        }

        DocumentosLegais::registrarAceite($usuario, $documentos, $request, $dados['dispositivo_identificador'] ?? null);

        $usuario->loadMissing(['empresa', 'perfil', 'dispositivo']);
        $usuario->documentosPendentes = DocumentosLegais::pendentes($usuario);

        return response()->json(['usuario' => new UsuarioResource($usuario)]);
    }

    /** Histórico de aceites de um usuário, pra tela de usuários do admin (§5.5). */
    public function historico(Usuario $usuario): JsonResponse
    {
        $aceites = AceiteDocumentoLegal::query()
            ->with('documentoLegal')
            ->where('usuario_id', $usuario->id)
            ->orderByDesc('aceito_em')
            ->get()
            ->map(fn (AceiteDocumentoLegal $a) => [
                'tipo' => $a->documentoLegal->tipo->value,
                'versao' => $a->documentoLegal->versao,
                'aceito_em' => $a->aceito_em,
                'app' => $a->app,
                'ip' => $a->ip,
            ]);

        return response()->json(['data' => $aceites]);
    }

    private function buscar(string $slug, mixed $versao): DocumentoLegal
    {
        $tipo = TipoDocumentoLegal::doSlug($slug);
        abort_if($tipo === null, 404);

        $documento = is_string($versao) && $versao !== ''
            ? DocumentoLegal::query()->where('tipo', $tipo)->where('versao', $versao)->publicado()->first()
            : DocumentoLegal::vigente($tipo);

        abort_if($documento === null, 404);

        return $documento;
    }
}
