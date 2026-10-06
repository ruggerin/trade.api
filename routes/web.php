<?php

use App\Http\Controllers\DocumentoLegalController;
use App\Http\Controllers\ManualController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Documentação de produto (markdown, pasta docs/ na raiz do monorepo) — em /manual, não /docs,
// porque dedoc/scramble já é dono de /docs/api (referência da API gerada a partir do código).
// /manual sozinho REDIRECIONA pra /manual/README.md em vez de renderizar direto — os links
// relativos de um .md pro outro (ex. "00-VISAO-GERAL.md") resolvem relativos à URL atual, e só
// funcionam se toda página do manual tiver o nome do arquivo no path (senão "00-VISAO-GERAL.md"
// vira /00-VISAO-GERAL.md em vez de /manual/00-VISAO-GERAL.md).
Route::redirect('/manual', '/manual/README.md')->name('manual.index');
Route::get('/manual/{arquivo}', [ManualController::class, 'show'])
    ->where('arquivo', '[A-Za-z0-9\-]+\.md')
    ->name('manual.show');

// Termos de Uso e Política de Privacidade públicos (docs/58 §5.2) — URL da Play Store e dos
// links nos logins. `?versao=2026-10-06` abre uma versão anterior.
Route::get('/{slug}', [DocumentoLegalController::class, 'pagina'])
    ->where('slug', 'termos-de-uso|politica-de-privacidade')
    ->name('documentos-legais.pagina');
