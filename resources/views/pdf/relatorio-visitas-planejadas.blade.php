<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Cumprimento de visitas</title>
    <style>
        {{-- dompdf: só CSS 2.1, layout de tabela (mesmo raciocínio de pdf/rota-visita). --}}
        @page { margin: 24px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111827; }
        .titulo { font-size: 16px; font-weight: bold; }
        .meta { font-size: 8px; color: #6b7280; margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #eef2ff; color: #3730a3; text-align: right; padding: 4px 6px; border: 1px solid #c7d2fe; }
        td { text-align: right; padding: 3px 6px; border: 1px solid #e5e7eb; }
        th.esq, td.esq { text-align: left; }
        tr.total td { font-weight: bold; background-color: #f3f4f6; }
        .atraso { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <div class="titulo">Cumprimento de visitas — planejado × executado</div>
    <div class="meta">
        Período: {{ \Carbon\Carbon::parse($dados['periodo']['data_inicio'])->format('d/m/Y') }}
        a {{ \Carbon\Carbon::parse($dados['periodo']['data_fim'])->format('d/m/Y') }}
        @if ($filtros) — {{ $filtros }} @endif
        — gerado em {{ $geradoEm->format('d/m/Y H:i') }}
    </div>
    <table>
        <thead>
            <tr>
                <th class="esq">Dia</th><th class="esq">Promotor</th><th>Planejadas</th><th>Cumpridas</th>
                <th>Em andamento</th><th>Atrasadas</th><th>A vencer</th><th>Espontâneas</th><th>Cumprimento</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($dados['linhas'] as $l)
                <tr>
                    <td class="esq">{{ \Carbon\Carbon::parse($l['data'])->format('d/m/Y') }}</td>
                    <td class="esq">{{ $l['promotor']['nome'] ?? 'Fila aberta' }}</td>
                    <td>{{ $l['planejadas'] }}</td>
                    <td>{{ $l['cumpridas'] }}</td>
                    <td>{{ $l['em_andamento'] }}</td>
                    <td class="{{ $l['atrasadas'] > 0 ? 'atraso' : '' }}">{{ $l['atrasadas'] }}</td>
                    <td>{{ $l['a_vencer'] }}</td>
                    <td>{{ $l['espontaneas'] }}</td>
                    <td>{{ $l['percentual_cumprimento'] === null ? '—' : $l['percentual_cumprimento'].'%' }}</td>
                </tr>
            @empty
                <tr><td class="esq" colspan="9">Nada planejado nem executado nesse período.</td></tr>
            @endforelse
            @php($t = $dados['total'])
            <tr class="total">
                <td class="esq" colspan="2">Total</td>
                <td>{{ $t['planejadas'] }}</td><td>{{ $t['cumpridas'] }}</td><td>{{ $t['em_andamento'] }}</td>
                <td>{{ $t['atrasadas'] }}</td><td>{{ $t['a_vencer'] }}</td><td>{{ $t['espontaneas'] }}</td>
                <td>{{ $t['percentual_cumprimento'] === null ? '—' : $t['percentual_cumprimento'].'%' }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
