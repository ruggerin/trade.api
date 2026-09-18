<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Rota de Visita — {{ $usuario->nome }}</title>
    <style>
        {{-- dompdf entende só um subconjunto de CSS 2.1 — sem flexbox/grid, sem custom
             properties. Layout de tabela tradicional, igual o print de referência que motivou
             esta view (ver docs/10-AGENDA-VISITA.md §9). --}}
        @page {
            margin: 24px 28px;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #111827;
        }
        .cabecalho {
            border-bottom: 2px solid #111827;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .cabecalho-topo {
            width: 100%;
        }
        .cabecalho-topo td {
            vertical-align: top;
        }
        .titulo {
            font-size: 16px;
            font-weight: bold;
        }
        .subtitulo {
            font-size: 10px;
            color: #4b5563;
            margin-top: 2px;
        }
        .metadados {
            text-align: right;
            font-size: 8px;
            color: #6b7280;
        }
        .criterios {
            margin-top: 8px;
            font-size: 8px;
            color: #374151;
        }
        .criterios strong {
            color: #111827;
        }
        .grupo-dia {
            margin-top: 14px;
        }
        .grupo-dia-titulo {
            background-color: #eef2ff;
            color: #3730a3;
            font-weight: bold;
            font-size: 10px;
            padding: 4px 6px;
            border: 1px solid #c7d2fe;
        }
        table.rota {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.rota th {
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 4px 5px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            color: #4b5563;
        }
        table.rota td {
            border: 1px solid #e5e7eb;
            padding: 4px 5px;
            vertical-align: top;
        }
        table.rota tr:nth-child(even) td {
            background-color: #f9fafb;
        }
        .tag-tipo {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            color: #ffffff;
            font-size: 8px;
        }
        .sem-itens {
            font-size: 9px;
            color: #9ca3af;
            font-style: italic;
        }
        .rodape {
            margin-top: 16px;
            font-size: 7px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="cabecalho">
        <table class="cabecalho-topo">
            <tr>
                <td>
                    <div class="titulo">Rota de Visita — {{ $usuario->nome }}</div>
                    <div class="subtitulo">{{ $empresa->nome_fantasia ?? $empresa->razao_social }} · Rota fixa semanal</div>
                </td>
                <td class="metadados">
                    Gerado em {{ $geradoEm->format('d/m/Y \à\s H:i') }}
                </td>
            </tr>
        </table>
        <div class="criterios">
            <strong>Ordenação:</strong> Dia da Semana &nbsp;·&nbsp;
            <strong>Critérios:</strong> Promotor = {{ $usuario->nome }}
        </div>
    </div>

    @forelse ($grupos as $grupo)
        <div class="grupo-dia">
            <div class="grupo-dia-titulo">Dia da Semana: {{ mb_strtoupper($grupo['nome']) }}</div>
            <table class="rota">
                <thead>
                    <tr>
                        <th style="width: 4%;">Seq.</th>
                        <th style="width: 7%;">Cód.</th>
                        <th style="width: 16%;">Razão Social</th>
                        <th style="width: 14%;">Fantasia</th>
                        <th style="width: 18%;">Endereço</th>
                        <th style="width: 10%;">Bairro</th>
                        <th style="width: 9%;">Telefone</th>
                        <th style="width: 7%;">Horário</th>
                        <th style="width: 9%;">Tipo</th>
                        <th style="width: 6%;">Mix</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grupo['itens'] as $indice => $agenda)
                        <tr>
                            <td>{{ $indice + 1 }}</td>
                            <td>{{ $agenda->pontoVenda->codigo_externo ?? '—' }}</td>
                            <td>{{ $agenda->pontoVenda->razao_social }}</td>
                            <td>{{ $agenda->pontoVenda->fantasia }}</td>
                            <td>{{ trim(($agenda->pontoVenda->endereco ?? '').($agenda->pontoVenda->numero ? ', '.$agenda->pontoVenda->numero : '')) ?: '—' }}</td>
                            <td>{{ $agenda->pontoVenda->bairro ?? '—' }}</td>
                            <td>{{ $agenda->pontoVenda->telefone ?? '—' }}</td>
                            <td>{{ $agenda->horario_previsto ?? '—' }}</td>
                            <td>
                                @if ($agenda->tipoVisita)
                                    <span class="tag-tipo" style="background-color: {{ $agenda->tipoVisita->cor }};">{{ $agenda->tipoVisita->descricao }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $agenda->pontoVenda->sortimento_count ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="sem-itens">Nenhuma rota semanal cadastrada pra este promotor.</p>
    @endforelse

    <div class="rodape">
        PDV App — Relatório gerado automaticamente a partir do Planejador de Visitas.
    </div>
</body>
</html>
