<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} — Horus</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --indigo: #4f46e5;
            --texto: #1b1b2b;
            --texto-secundario: #6e6e85;
            --borda: #e7e7ef;
            --fundo: #f3f4f8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--fundo);
            color: var(--texto);
            font-family: "IBM Plex Sans", Roboto, system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }
        main {
            max-width: 820px;
            margin: 32px auto;
            padding: 32px 40px 40px;
            background: #fff;
            border: 1px solid var(--borda);
            border-radius: 8px;
        }
        .marca { font-weight: 700; color: var(--indigo); letter-spacing: .02em; margin: 0 0 24px; }
        h1 { font-size: 26px; line-height: 1.25; margin: 0 0 8px; }
        h2 { font-size: 18px; margin: 32px 0 8px; }
        h3 { font-size: 15.5px; margin: 24px 0 6px; }
        a { color: var(--indigo); }
        hr { border: 0; border-top: 1px solid var(--borda); margin: 24px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; margin: 12px 0; }
        th, td { border: 1px solid var(--borda); padding: 6px 10px; text-align: left; vertical-align: top; }
        th { background: #f0f0f5; font-weight: 500; }
        .rodape { margin-top: 32px; color: var(--texto-secundario); font-size: 13px; }
        @media (max-width: 760px) {
            main { margin: 0; border: 0; border-radius: 0; padding: 24px 16px 32px; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
<main>
    <p class="marca">Horus</p>
    {!! $conteudoHtml !!}
    <p class="rodape">Versão {{ \Illuminate\Support\Carbon::parse($versao)->format('d/m/Y') }}</p>
</main>
</body>
</html>
