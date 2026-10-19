<?php

namespace App\Support;

use App\Models\ProdutoAuditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto do produto enviada pra cá (não URL externa) — usada pelo upload unitário do cadastro
 * (ProdutoAuditoriaController::uploadImagem) e pela importação em massa (ImportacaoImagensProdutos,
 * docs/89). Grava no mesmo disco das fotos de registro de visita (`config('filesystems.default')`),
 * pasta `produtos/`, nome UUID aleatório, e salva em `imagem_url` a URL absoluta da rota pública
 * `GET /produtos-auditoria/imagens/{arquivo}` — todo consumidor do campo (app, admin, planograma)
 * continua lendo uma URL comum. Nome novo a cada envio: a URL muda junto com a foto (sem cache
 * velho) e não dá pra adivinhar a de outro produto/empresa.
 */
class ImagemProduto
{
    public const PASTA = 'produtos';

    public const EXTENSOES = ['jpg', 'jpeg', 'png', 'webp'];

    public const TAMANHO_MAXIMO_KB = 5120;

    /** Regra de validação de um arquivo de imagem de produto (mesma no unitário e no lote). */
    public static function regraArquivo(): array
    {
        return ['file', 'max:'.self::TAMANHO_MAXIMO_KB, 'mimes:'.implode(',', self::EXTENSOES)];
    }

    /** Grava a foto, troca `imagem_url` e apaga a anterior se também era nossa. */
    public static function gravar(ProdutoAuditoria $produto, UploadedFile $arquivo): void
    {
        $nomeArquivo = Str::uuid().'.'.($arquivo->extension() ?: 'jpg');
        $arquivo->storeAs(self::PASTA, $nomeArquivo, config('filesystems.default'));

        $anterior = $produto->imagem_url;
        $produto->update(['imagem_url' => url('/api/produtos-auditoria/imagens/'.$nomeArquivo)]);
        self::apagarPropria($anterior);
    }

    public static function apagarPropria(?string $url): void
    {
        $caminho = self::caminhoPropria($url);
        if ($caminho) {
            Storage::disk(config('filesystems.default'))->delete($caminho);
        }
    }

    /**
     * Caminho no storage se a URL aponta pra uma foto enviada pra cá; null se é URL externa.
     * Compara só o path (não o host) — a URL guardada é absoluta com o APP_URL da época.
     */
    public static function caminhoPropria(?string $url): ?string
    {
        $path = $url ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($path) && preg_match('#^/api/produtos-auditoria/imagens/([0-9a-f-]{36}\.[a-z0-9]+)$#', $path, $m)
            ? self::PASTA.'/'.$m[1]
            : null;
    }
}
