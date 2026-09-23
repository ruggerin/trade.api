<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'PDV API') }}</title>
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
            --verde: #16a34a;
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
            gap: 12px;
        }
        .logo {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--indigo);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .logo span {
            width: 8px;
            height: 8px;
            border-radius: 3px;
            background: var(--amber);
        }
        header strong {
            font-size: 15px;
            font-weight: 600;
        }
        header .status {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: var(--texto-secundario);
        }
        header .ponto {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--verde);
            flex-shrink: 0;
        }

        main {
            max-width: 640px;
            margin: 0 auto;
            padding: 48px 24px 96px;
        }
        main p { margin: 0 0 16px; color: var(--texto); }
        main p.secundario { color: var(--texto-secundario); font-size: 14px; }

        main h2 {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--texto-terciario);
            text-transform: uppercase;
            margin: 36px 0 12px;
        }
        main h2:first-of-type { margin-top: 0; }

        code {
            background: var(--indigo-claro);
            color: var(--indigo-escuro);
            padding: 1.5px 6px;
            border-radius: 5px;
            font-family: "IBM Plex Mono", monospace;
            font-size: 0.87em;
        }

        .lista-links { display: flex; flex-direction: column; gap: 2px; }
        .link-doc {
            display: block;
            padding: 12px 14px;
            margin: 0 -14px;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
        }
        .link-doc:hover { background: #ffffff; }
        .link-doc .titulo {
            color: var(--indigo);
            font-weight: 500;
            font-size: 14.5px;
        }
        .link-doc .titulo::after { content: " →"; }
        .link-doc .legenda {
            color: var(--texto-secundario);
            font-size: 13px;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <header>
        <div class="logo"><span></span></div>
        <strong>{{ config('app.name', 'PDV API') }}</strong>
        <div class="status">
            <span class="ponto"></span>
            no ar &middot; {{ app()->environment() }} &middot; Laravel {{ app()->version() }} &middot; PHP {{ PHP_VERSION }}
        </div>
    </header>

    <main>
        <p>
            Backend do sistema de PDV/auditoria de campo &mdash; atende o admin web e o app mobile
            do promotor. Não é um site público; se você chegou aqui de fora, provavelmente queria
            um dos outros dois.
        </p>

        <h2>Documentação</h2>
        <div class="lista-links">
            <a class="link-doc" href="{{ route('manual.show', ['arquivo' => 'README.md']) }}">
                <div class="titulo">Manual do projeto</div>
                <div class="legenda">Decisões de produto, modelagem e histórico — a pasta <code>docs/</code>, escrita à mão.</div>
            </a>
            <a class="link-doc" href="/docs/api">
                <div class="titulo">Referência da API</div>
                <div class="legenda">Endpoints, parâmetros e schemas — gerado a partir do código (Scramble/OpenAPI).</div>
            </a>
        </div>
    </main>
</body>
</html>
