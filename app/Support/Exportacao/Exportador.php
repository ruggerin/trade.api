<?php

namespace App\Support\Exportacao;

use App\Models\Usuario;
use App\Support\Exportacao\Escritores\CsvEscritor;
use App\Support\Exportacao\Escritores\XlsxEscritor;
use App\Support\Fuso;
use Illuminate\Support\Str;

/**
 * Gera o arquivo de uma rotina (docs/68 §4). Síncrono, com teto de linhas: passou do teto, recusa
 * com mensagem — nunca corta em silêncio (o segundo plano é a Fase 4 do doc).
 */
final class Exportador
{
    /** Teto síncrono. Acima disso o usuário precisa refinar o filtro. */
    public const LIMITE_LINHAS = 20000;

    public const FORMATOS = ['csv', 'xlsx'];

    private const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * @param  array<string, mixed>  $filtros  já filtrados pela whitelist da rotina
     * @return array{caminho: string, nome: string, mime: string, linhas: int}
     */
    public function gerar(Rotina $rotina, string $formato, array $filtros, Usuario $usuario, ?string $descricaoFiltros = null): array
    {
        $rotina->configurar($filtros);
        $colunas = $rotina->colunas();
        $fuso = Fuso::daEmpresa($usuario->empresa);
        $formatador = new Formatador($fuso);

        $caminho = tempnam(sys_get_temp_dir(), 'exp_');
        $escritor = $formato === 'xlsx'
            ? new XlsxEscritor($caminho, $colunas, $formatador, $rotina->titulo())
            : new CsvEscritor($caminho, $colunas, $formatador);

        $total = 0;
        try {
            foreach ($rotina->linhas($filtros, $usuario) as $linha) {
                if ($total >= (int) config('exportacao.limite_linhas', self::LIMITE_LINHAS)) {
                    abort(422, 'Esta exportação passa de '.number_format((int) config('exportacao.limite_linhas', self::LIMITE_LINHAS), 0, ',', '.').' linhas. Refine o filtro (período, loja ou promotor) e tente de novo.');
                }
                $escritor->linha($linha);
                $total++;
            }

            if ($escritor instanceof XlsxEscritor) {
                $escritor->fechar($this->sobre($rotina, $usuario, $filtros, $descricaoFiltros, $total, $fuso));
            } else {
                $escritor->fechar();
            }
        } catch (\Throwable $e) {
            @unlink($caminho);
            throw $e;
        }

        return [
            'caminho' => $caminho,
            'nome' => $this->nomeDoArquivo($rotina, $usuario, $filtros, $formato),
            'mime' => self::MIME[$formato],
            'linhas' => $total,
        ];
    }

    /**
     * Aba "Sobre" do XLSX (docs/68 §4.2): o arquivo explica a si mesmo.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array{0: string, 1: string}>
     */
    private function sobre(Rotina $rotina, Usuario $usuario, array $filtros, ?string $descricaoFiltros, int $total, string $fuso): array
    {
        $periodo = ($filtros['data_inicio'] ?? null) || ($filtros['data_fim'] ?? null)
            ? ($filtros['data_inicio'] ?? '…').' a '.($filtros['data_fim'] ?? '…')
            : 'Sem período';

        return [
            ['Relatório', $rotina->titulo()],
            ['Empresa', (string) ($usuario->empresa?->nome_fantasia ?? '')],
            ['Período', $periodo],
            ['Filtros aplicados', $descricaoFiltros ?: 'Nenhum'],
            ['Total de linhas', (string) $total],
            ['Gerado por', $usuario->nome],
            ['Gerado em', now($fuso)->format('d/m/Y H:i').' ('.$fuso.')'],
        ];
    }

    /** `{empresa}-{rotina}-{inicio}-a-{fim}.{ext}`; sem período, a data de hoje. Sem acento nem espaço. */
    private function nomeDoArquivo(Rotina $rotina, Usuario $usuario, array $filtros, string $formato): string
    {
        $partes = [
            Str::slug((string) ($usuario->empresa?->nome_fantasia ?? 'horus')),
            $rotina::chave(),
        ];
        if (! empty($filtros['data_inicio']) && ! empty($filtros['data_fim'])) {
            $partes[] = $filtros['data_inicio'].'-a-'.$filtros['data_fim'];
        } else {
            $partes[] = now(Fuso::daEmpresa($usuario->empresa))->toDateString();
        }

        return Str::of(implode('-', array_filter($partes)))->slug('-')->value().'.'.$formato;
    }
}
