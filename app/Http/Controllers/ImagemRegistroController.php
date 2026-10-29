<?php

namespace App\Http\Controllers;

use App\Enums\StatusVisita;
use App\Enums\UserType;
use App\Models\ImagemRegistro;
use App\Models\Visita;
use App\Support\FotosNomeadas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve o arquivo de uma foto de evidência — não depende mais de um registro específico
 * (`VisitaRegistroController::imagem`, removido), já que uma foto pode evidenciar N registros.
 * Ver docs/21-EVIDENCIA-EM-FOTOS.md.
 */
class ImagemRegistroController extends Controller
{
    public function show(Request $request, Visita $visita, ImagemRegistro $imagem): StreamedResponse
    {
        if ($request->user()->user_type === UserType::PROMOTOR && $visita->usuario_id !== $request->user()->id) {
            abort(403, 'Você não tem acesso a esta visita.');
        }

        // ImagemRegistro não é tenant-aware por conta própria (herda de Visita) — confirma
        // manualmente que a imagem pertence mesmo à visita da rota, mesmo padrão que
        // VisitaRegistroController já usava.
        // Foto de visita anterior reaproveitada numa pergunta FOTO (docs/98) também abre pela visita
        // que a usa: ela está vinculada a um registro desta visita.
        abort_if($imagem->visita_id !== $visita->id && ! $imagem->registros()->where('visita_id', $visita->id)->exists(), 404);

        return Storage::disk(config('filesystems.default'))->response($imagem->caminho);
    }

    /**
     * Galeria Horus (docs/98 §9.1): fotos de visitas anteriores nesta loja que o promotor pode
     * reaproveitar numa pergunta FOTO. Só o dono da visita, só com ela aberta.
     */
    public function anteriores(Request $request, Visita $visita): JsonResponse
    {
        $this->exigirDonoComVisitaAberta($request, $visita);

        $fotos = FotosNomeadas::historicoDaLoja($visita)
            ->with(['visita' => fn ($q) => $q->withoutGlobalScopes()->with('usuario:id,nome'),
                'registros' => fn ($q) => $q->whereNull('cancelado_em')->with('tipoRegistro:id,descricao', 'tipoRegistro.campos:id,tipo_registro_id,chave,rotulo')])
            ->orderByRaw('coalesce(capturada_em, created_at) desc')
            ->limit(FotosNomeadas::LIMITE_HISTORICO)
            ->get();

        return response()->json([
            'dias' => FotosNomeadas::diasHistorico($visita->empresa()->withoutGlobalScopes()->first()),
            'fotos' => $fotos->map(function (ImagemRegistro $img) use ($visita) {
                $registro = $img->registros->first();
                $campo = $registro?->tipoRegistro?->campos?->firstWhere('chave', $registro->pivot->campo_chave);

                return [
                    'id' => $img->uuid,
                    'url' => url("/api/visitas/{$visita->uuid}/fotos-anteriores/{$img->uuid}"),
                    'capturada_em' => ($img->capturada_em ?? $img->created_at)?->toIso8601String(),
                    'visita_em' => $img->visita?->inicio_data?->toIso8601String(),
                    'promotor' => $img->visita?->usuario?->nome,
                    'formulario' => $registro?->tipoRegistro?->descricao,
                    'pergunta' => $campo?->rotulo,
                ];
            })->values(),
        ]);
    }

    public function arquivoAnterior(Request $request, Visita $visita, ImagemRegistro $imagem): StreamedResponse
    {
        $this->exigirDonoComVisitaAberta($request, $visita);
        abort_unless(FotosNomeadas::historicoDaLoja($visita)->whereKey($imagem->id)->exists(), 404);

        return Storage::disk(config('filesystems.default'))->response($imagem->caminho);
    }

    private function exigirDonoComVisitaAberta(Request $request, Visita $visita): void
    {
        abort_if($visita->usuario_id !== $request->user()->id, 403, 'Você não tem acesso a esta visita.');
        abort_if($visita->status !== StatusVisita::ABERTA, 422, 'A visita não está mais aberta.');
    }
}
