<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Visualizador leve da pasta `docs/` (markdown) direto no navegador — sem isso, o link da home
 * (`resources/views/welcome.blade.php`) apontaria pra um caminho de arquivo que só existe no
 * checkout local, não numa URL de verdade. Servido em `/manual` (não `/docs`) porque
 * `dedoc/scramble` já é dono de `/docs/api` (referência da API gerada a partir do código — ver
 * `config/scramble.php`) — são duas documentações diferentes: esta aqui é a de produto/decisões,
 * escrita à mão. `docs/` é um repositório Git separado do `api/` (ver docs/00-VISAO-GERAL.md —
 * "cada app é um repositório próprio"), então em produção ele pode não estar no mesmo servidor;
 * por isso o 404 amigável em vez de assumir que a pasta existe.
 */
class ManualController extends Controller
{
    public function show(string $arquivo = 'README.md'): Response
    {
        $caminhoDocs = realpath(base_path('../docs'));

        if ($caminhoDocs === false) {
            return $this->paginaErro(
                'Documentação não encontrada neste servidor',
                'A pasta docs/ é um repositório separado do api/ — parece que não foi feito o checkout dela aqui.',
            );
        }

        $caminhoArquivo = realpath($caminhoDocs.'/'.$arquivo);

        // `realpath` já resolve `..`, mas confere de novo que o resultado continua DENTRO de
        // docs/ — trava qualquer tentativa de escapar da pasta via nome de arquivo malicioso.
        if ($caminhoArquivo === false || ! str_starts_with($caminhoArquivo, $caminhoDocs) || ! str_ends_with($caminhoArquivo, '.md')) {
            return $this->paginaErro('Página não encontrada', "Não existe \"{$arquivo}\" em docs/.");
        }

        $conversor = new MarkdownConverter($this->ambiente());
        $html = $conversor->convert(file_get_contents($caminhoArquivo))->getContent();

        return response(view('manual', [
            'titulo' => $arquivo,
            'conteudoHtml' => $html,
        ]));
    }

    private function ambiente(): Environment
    {
        $ambiente = new Environment(['heading_permalink' => ['symbol' => '', 'insert' => 'before']]);
        $ambiente->addExtension(new CommonMarkCoreExtension());
        $ambiente->addExtension(new GithubFlavoredMarkdownExtension());
        $ambiente->addExtension(new HeadingPermalinkExtension());

        return $ambiente;
    }

    private function paginaErro(string $titulo, string $mensagem): Response
    {
        return response(view('manual', [
            'titulo' => $titulo,
            'conteudoHtml' => '<p>'.e($mensagem).'</p>',
        ]), 404);
    }
}
