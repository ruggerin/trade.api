<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Respostas — {{ $dados['tipo_registro']['descricao'] }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111827; }
        .titulo { font-size: 16px; font-weight: bold; }
        .meta { font-size: 8px; color: #6b7280; margin: 2px 0 10px; }
        h2 { font-size: 11px; background-color: #eef2ff; color: #3730a3; padding: 4px 6px; border: 1px solid #c7d2fe; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 3px 6px; border: 1px solid #e5e7eb; background-color: #f9fafb; }
        td { padding: 3px 6px; border: 1px solid #e5e7eb; }
        td.num { text-align: right; width: 70px; }
        .vazio { color: #6b7280; }
    </style>
</head>
<body>
    <div class="titulo">Respostas por pergunta — {{ $dados['tipo_registro']['descricao'] }}</div>
    <div class="meta">
        Período: {{ \Carbon\Carbon::parse($dados['periodo']['data_inicio'])->format('d/m/Y') }}
        a {{ \Carbon\Carbon::parse($dados['periodo']['data_fim'])->format('d/m/Y') }}
        @if ($filtros) — {{ $filtros }} @endif
        — {{ $dados['total_registros'] }} registro(s) — gerado em {{ $geradoEm->format('d/m/Y H:i') }}
    </div>

    @if (isset($dados['rupturas']))
        <h2>Rupturas — {{ $dados['rupturas']['total'] }} no total</h2>
        <table>
            @forelse ($dados['rupturas']['por_produto'] as $p)
                <tr><td>{{ $p['produto'] }}</td><td class="num">{{ $p['quantidade'] }}</td></tr>
            @empty
                <tr><td class="vazio">Nenhuma ruptura no período.</td></tr>
            @endforelse
        </table>
    @endif

    @foreach ($dados['campos'] as $campo)
        <h2>{{ $campo['rotulo'] }} <span class="vazio">({{ $campo['respostas'] }} resposta(s))</span></h2>
        @if ($campo['respostas'] === 0)
            <div class="vazio">Sem respostas no período.</div>
        @elseif (isset($campo['contagem']))
            <table>
                @foreach ($campo['contagem'] as $c)
                    <tr><td>{{ $c['valor'] }}</td><td class="num">{{ $c['quantidade'] }}</td></tr>
                @endforeach
            </table>
        @elseif (! empty($campo['estatisticas']))
            @php($e = $campo['estatisticas'])
            <table>
                <tr><th>Soma</th><th>Média</th><th>Mínimo</th><th>Máximo</th></tr>
                <tr>
                    <td>{{ number_format($e['soma'], 2, ',', '.') }}</td><td>{{ number_format($e['media'], 2, ',', '.') }}</td>
                    <td>{{ number_format($e['minimo'], 2, ',', '.') }}</td><td>{{ number_format($e['maximo'], 2, ',', '.') }}</td>
                </tr>
            </table>
        @elseif (isset($campo['ausencias']))
            <div class="vazio">Produtos mais ausentes ({{ $campo['ausencias']['checklists'] }} checklist(s))</div>
            <table>
                @foreach ($campo['ausencias']['produtos'] as $p)
                    <tr><td>{{ $p['produto'] }}</td><td class="num">{{ $p['vezes_ausente'] }}</td></tr>
                @endforeach
            </table>
        @else
            <table>
                @foreach ($campo['ultimas'] ?? [] as $texto)
                    <tr><td>{{ $texto }}</td></tr>
                @endforeach
            </table>
        @endif
    @endforeach
</body>
</html>
