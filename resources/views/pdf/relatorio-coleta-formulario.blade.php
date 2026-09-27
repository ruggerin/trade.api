<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $dados['titulo'] }}</title>
    {{-- Coleta por Formulário (docs/39-RELATORIO-ANALITICO-PIVOT.md) — a matriz chega pronta do
         front (RelatorioController::respostasFormularioAnaliticoPdf), aqui só desenha. --}}
    <style>
        @page { margin: 20px 22px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #111827; }
        .titulo { font-size: 14px; font-weight: bold; }
        .meta { font-size: 8px; color: #6b7280; margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 3px 4px; border: 1px solid #e5e7eb; background-color: #f3f4f6; text-align: center; }
        td { padding: 3px 4px; border: 1px solid #e5e7eb; }
        tfoot td { background-color: #eef2ff; font-weight: bold; }
        .vazio { color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="titulo">{{ $dados['titulo'] }}</div>
    <div class="meta">
        @if (! empty($dados['subtitulo'])) {{ $dados['subtitulo'] }} — @endif
        gerado em {{ $geradoEm->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            @foreach ($dados['cabecalho'] as $linha)
                <tr>
                    @foreach ($linha as $celula)
                        <th colspan="{{ $celula['colunas'] ?? 1 }}" rowspan="{{ $celula['linhas'] ?? 1 }}">{{ $celula['texto'] ?? '' }}</th>
                    @endforeach
                </tr>
            @endforeach
        </thead>
        <tbody>
            @forelse ($dados['linhas'] as $linha)
                <tr>
                    @foreach ($linha as $valor)
                        <td @if ($valor === null || $valor === '' || $valor === '—') class="vazio" @endif>{{ $valor ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="vazio">Nenhuma coleta no período.</td></tr>
            @endforelse
        </tbody>
        @if (! empty($dados['rodape']))
            <tfoot>
                @foreach ($dados['rodape'] as $linha)
                    <tr>
                        @foreach ($linha as $valor)
                            <td>{{ $valor ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tfoot>
        @endif
    </table>
</body>
</html>
