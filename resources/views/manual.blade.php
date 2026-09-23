<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} — {{ config('app.name', 'PDV API') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --indigo: #4f46e5;
            --indigo-escuro: #3730a3;
            --indigo-claro: #eef2ff;
            --amber: #f59e0b;
            --texto: #111827;
            --texto-secundario: #4b5563;
            --texto-terciario: #9ca3af;
            --borda: rgba(0, 0, 0, 0.12);
            --fundo: #f9fafb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--fundo);
            color: var(--texto);
            font-family: "IBM Plex Sans", "Roboto", system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.7;
        }
        header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--borda);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            position: sticky;
            top: 0;
        }
        header a.voltar {
            color: var(--texto-secundario);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        header a.voltar:hover { color: var(--indigo); }
        header .separador { color: var(--borda); }
        header .arquivo {
            font-size: 13px;
            color: var(--texto-terciario);
            font-family: "IBM Plex Mono", monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        main {
            max-width: 760px;
            margin: 0 auto;
            padding: 40px 24px 96px;
        }

        main h1, main h2, main h3, main h4 { font-weight: 600; scroll-margin-top: 72px; }
        main h1 { font-size: 26px; margin: 0 0 20px; }
        main h2 { font-size: 20px; margin: 40px 0 14px; padding-top: 8px; border-top: 1px solid var(--borda); }
        main h2:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
        main h3 { font-size: 16.5px; margin: 28px 0 10px; }
        main h4 { font-size: 15px; margin: 20px 0 8px; color: var(--texto-secundario); }
        main a.heading-permalink { display: none; }

        main p, main ul, main ol { margin: 0 0 14px; color: var(--texto); }
        main ul, main ol { padding-left: 22px; }
        main li { margin-bottom: 4px; }
        main li > p { margin-bottom: 4px; }

        main a { color: var(--indigo); text-decoration: none; }
        main a:hover { text-decoration: underline; }

        main code {
            background: var(--indigo-claro);
            color: var(--indigo-escuro);
            padding: 1.5px 6px;
            border-radius: 5px;
            font-family: "IBM Plex Mono", monospace;
            font-size: 0.87em;
        }
        main pre {
            background: #111827;
            color: #e5e7eb;
            padding: 16px 18px;
            border-radius: 10px;
            overflow-x: auto;
            margin: 0 0 16px;
        }
        main pre code {
            background: none;
            color: inherit;
            padding: 0;
            font-size: 13px;
            line-height: 1.6;
        }

        main blockquote {
            margin: 0 0 16px;
            padding: 4px 16px;
            border-left: 3px solid var(--amber);
            background: #fffbeb;
            color: #78350f;
            border-radius: 0 8px 8px 0;
        }
        main blockquote p { color: inherit; margin: 8px 0; }
        main blockquote strong { color: #7c2d12; }

        main table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 20px;
            font-size: 13.5px;
        }
        main th, main td {
            border: 1px solid var(--borda);
            padding: 8px 12px;
            text-align: left;
            vertical-align: top;
        }
        main th {
            background: var(--fundo);
            font-weight: 600;
            color: var(--texto-secundario);
        }

        main hr {
            border: none;
            border-top: 1px solid var(--borda);
            margin: 32px 0;
        }

        main del { color: var(--texto-terciario); }

        .erro-nota {
            font-size: 13px;
            color: var(--texto-terciario);
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <header>
        <a class="voltar" href="{{ route('manual.show', ['arquivo' => 'README.md']) }}">&larr; Manual</a>
        @if ($titulo !== 'README.md')
            <span class="separador">/</span>
            <span class="arquivo">{{ $titulo }}</span>
        @endif
    </header>
    <main>
        {!! $conteudoHtml !!}
    </main>
</body>
</html>
