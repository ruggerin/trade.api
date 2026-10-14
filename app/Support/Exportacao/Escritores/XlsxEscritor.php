<?php

namespace App\Support\Exportacao\Escritores;

use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Formatador;

/**
 * XLSX de verdade (docs/68 §4.2), sem dependência externa: aba "Dados" com tipos reais (número é
 * número, data é data), cabeçalho congelado e larguras ajustadas; aba "Sobre" com o contexto do
 * arquivo (empresa, filtros, quem gerou e quando). Texto vai como string inline — nada vira fórmula.
 */
final class XlsxEscritor
{
    // Índices de estilo (cellXfs) definidos em estilos().
    private const S_CABECALHO = 1;
    private const S_DATA = 2;
    private const S_DATA_HORA = 3;
    private const S_DECIMAL = 4;
    private const S_INTEIRO = 5;
    private const S_TEXTO = 6;
    private const S_TITULO = 7;

    private string $linhasXml = '';

    private int $numeroLinha = 1;

    /** @var list<int> */
    private array $larguras = [];

    /** @param list<Coluna> $colunas */
    public function __construct(
        private readonly string $caminho,
        private readonly array $colunas,
        private readonly Formatador $formatador,
        private readonly string $titulo,
    ) {
        $celulas = '';
        foreach ($colunas as $i => $c) {
            $celulas .= $this->celulaTexto($i, 1, $c->rotulo, self::S_CABECALHO);
            $this->larguras[$i] = max(10, mb_strlen($c->rotulo) + 4);
        }
        $this->linhasXml .= '<row r="1">'.$celulas.'</row>';
    }

    /** @param array<string, mixed> $linha */
    public function linha(array $linha): void
    {
        $this->numeroLinha++;
        $r = $this->numeroLinha;
        $celulas = '';

        foreach ($this->colunas as $i => $c) {
            [$tipo, $valor] = $this->formatador->excel($c, $linha[$c->chave] ?? null);
            if ($tipo === 'vazio') {
                continue;
            }
            if ($tipo === 'n') {
                $estilo = match ($c->tipo) {
                    'data' => self::S_DATA,
                    'data_hora' => self::S_DATA_HORA,
                    'numero', 'moeda' => self::S_DECIMAL,
                    default => self::S_INTEIRO,
                };
                // Repr. decimal estável (sem notação científica) e com ponto, como o XLSX exige.
                $celulas .= '<c r="'.$this->ref($i, $r).'" s="'.$estilo.'"><v>'.rtrim(rtrim(number_format((float) $valor, 6, '.', ''), '0'), '.').'</v></c>';
            } else {
                $celulas .= $this->celulaTexto($i, $r, (string) $valor, self::S_TEXTO);
                // Só as primeiras linhas definem a largura — o suficiente e barato.
                if ($r <= 200) {
                    $this->larguras[$i] = min(60, max($this->larguras[$i], mb_strlen((string) $valor) + 2));
                }
            }
        }

        $this->linhasXml .= '<row r="'.$r.'">'.$celulas.'</row>';
    }

    /**
     * Fecha o arquivo. `sobre` = pares rótulo/valor que vão na aba "Sobre".
     *
     * @param  list<array{0: string, 1: string}>  $sobre
     */
    public function fechar(array $sobre): void
    {
        $zip = new ZipMinimo;
        $zip->adicionar('[Content_Types].xml', $this->tiposDeConteudo());
        $zip->adicionar('_rels/.rels', $this->relsRaiz());
        $zip->adicionar('xl/workbook.xml', $this->workbook());
        $zip->adicionar('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->adicionar('xl/styles.xml', $this->estilos());
        $zip->adicionar('xl/worksheets/sheet1.xml', $this->planilhaDados());
        $zip->adicionar('xl/worksheets/sheet2.xml', $this->planilhaSobre($sobre));
        $zip->gravar($this->caminho);
    }

    // ---- partes do pacote ----

    private function planilhaDados(): string
    {
        $cols = '';
        foreach ($this->larguras as $i => $l) {
            $n = $i + 1;
            $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.$l.'" customWidth="1"/>';
        }
        $ultima = $this->ref(max(0, count($this->colunas) - 1), max(1, $this->numeroLinha));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$ultima.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$this->linhasXml.'</sheetData>'
            .'</worksheet>';
    }

    /** @param list<array{0: string, 1: string}> $sobre */
    private function planilhaSobre(array $sobre): string
    {
        $linhas = '<row r="1">'.$this->celulaTexto(0, 1, $this->titulo, self::S_TITULO).'</row>';
        $r = 2;
        foreach ($sobre as [$rotulo, $valor]) {
            $r++;
            $linhas .= '<row r="'.$r.'">'
                .$this->celulaTexto(0, $r, $rotulo, self::S_CABECALHO)
                .$this->celulaTexto(1, $r, $valor, self::S_TEXTO)
                .'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="90" customWidth="1"/></cols>'
            .'<sheetData>'.$linhas.'</sheetData></worksheet>';
    }

    private function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="dd/mm/yyyy"/>'
            .'<numFmt numFmtId="165" formatCode="dd/mm/yyyy\ hh:mm"/>'
            .'<numFmt numFmtId="166" formatCode="#,##0.00"/>'
            .'</numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8E8F5"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function tiposDeConteudo(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Dados" sheetId="1" r:id="rId1"/><sheet name="Sobre" sheetId="2" r:id="rId2"/></sheets>'
            .'</workbook>';
    }

    private function relsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    // ---- células ----

    private function celulaTexto(int $coluna, int $linha, string $texto, int $estilo): string
    {
        return '<c r="'.$this->ref($coluna, $linha).'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'
            .$this->xml($texto).'</t></is></c>';
    }

    /** "A1", "B7", "AA10" a partir de índice de coluna (0 = A) e número de linha. */
    private function ref(int $coluna, int $linha): string
    {
        $letras = '';
        $n = $coluna + 1;
        while ($n > 0) {
            $resto = ($n - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $n = intdiv($n - 1, 26);
        }

        return $letras.$linha;
    }

    /** Escapa e remove caracteres de controle que o XML 1.0 não permite. */
    private function xml(string $texto): string
    {
        $limpo = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto) ?? '';

        return htmlspecialchars($limpo, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
