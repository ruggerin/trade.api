<?php

/*
 * Simulador de atividade de campo (App\Console\Commands\SimularAtividade) — SÓ pra servidor de
 * teste/demonstração. Vazio (padrão) = desligado: o comando não faz nada e o agendador nem chama.
 */
return [
    // IDs das empresas simuladas, separados por vírgula (ex.: "1").
    'empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('SIMULADOR_ATIVIDADE_EMPRESAS', ''))))),

    // E-mails de promotores que NÃO são simulados (quem testa o app de verdade), por vírgula.
    'excluir_emails' => array_values(array_filter(array_map(
        fn ($email) => mb_strtolower(trim($email)),
        explode(',', (string) env('SIMULADOR_ATIVIDADE_EXCLUIR_EMAILS', '')),
    ))),
];
