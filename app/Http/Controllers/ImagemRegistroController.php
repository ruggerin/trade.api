<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\ImagemRegistro;
use App\Models\Visita;
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
        abort_if($imagem->visita_id !== $visita->id, 404);

        return Storage::disk(config('filesystems.default'))->response($imagem->caminho);
    }
}
