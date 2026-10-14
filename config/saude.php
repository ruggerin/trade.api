<?php

/**
 * Endpoint de saúde (GET /api/saude) pro Uptime Kuma e afins — docs/71.
 *
 * Sem `token`, a resposta pública traz só o veredito (`ok`, `alerta` ou `falha`). Com o token
 * (header `X-Saude-Token`), traz o detalhe de cada verificação. Sem SAUDE_TOKEN configurado, o
 * detalhe NUNCA é exposto — o padrão é o seguro.
 */
return [
    'token' => env('SAUDE_TOKEN'),

    // Limiares: acima do "alerta" a resposta continua 200 com status "alerta"; acima do "falha" é 503.
    'banco_latencia_alerta_ms' => (int) env('SAUDE_BANCO_LATENCIA_ALERTA_MS', 300),
    'banco_latencia_falha_ms' => (int) env('SAUDE_BANCO_LATENCIA_FALHA_MS', 2000),
    'banco_conexoes_alerta_pct' => (int) env('SAUDE_BANCO_CONEXOES_ALERTA_PCT', 80),

    // Memória do servidor (Linux): percentual DISPONÍVEL abaixo do qual avisa/falha.
    'memoria_disponivel_alerta_pct' => (int) env('SAUDE_MEMORIA_ALERTA_PCT', 15),
    'memoria_disponivel_falha_pct' => (int) env('SAUDE_MEMORIA_FALHA_PCT', 5),
    // Memória do próprio PHP nesta requisição, em % do memory_limit.
    'php_memoria_alerta_pct' => (int) env('SAUDE_PHP_MEMORIA_ALERTA_PCT', 70),

    // Disco onde mora o storage: percentual LIVRE.
    'disco_livre_alerta_pct' => (int) env('SAUDE_DISCO_ALERTA_PCT', 15),
    'disco_livre_falha_pct' => (int) env('SAUDE_DISCO_FALHA_PCT', 5),
    // Com mais que isso livre, o percentual não alerta (disco grande com 6% ainda tem folga).
    'disco_ok_acima_gb' => (float) env('SAUDE_DISCO_OK_ACIMA_GB', 20),

    // Fila (QUEUE_CONNECTION=database): job parado há mais que isso avisa; falhas nas últimas 24h.
    'fila_parado_alerta_min' => (int) env('SAUDE_FILA_PARADO_MIN', 15),
    'fila_falhas_24h_alerta' => (int) env('SAUDE_FILA_FALHAS_24H', 1),

    // Agendador (cron `schedule:run`): sem batimento há mais que isso avisa; mais que o dobro, falha.
    'agendador_atraso_alerta_min' => (int) env('SAUDE_AGENDADOR_MIN', 5),

    // Carga do servidor (load average 1 min) em múltiplos de núcleos.
    'carga_alerta_por_nucleo' => (float) env('SAUDE_CARGA_POR_NUCLEO', 2.0),
];
