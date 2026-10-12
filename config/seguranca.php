<?php

/*
 * Antipirataria e sessão do app (docs/67-LICENCIAMENTO-E-SESSAO-UNICA.md, Fases 2 e 3).
 */
return [

    // Versão mínima do app mobile (semver). Abaixo dela, toda chamada do app recebe 426 e o app
    // pede atualização. Vazio = sem exigência. App que não manda X-App-Version conta como 0.0.0.
    'versao_minima_app' => env('APP_VERSAO_MINIMA', ''),

    /*
     * Fase 3 — liga a exigência de atestação (Play Integrity / App Attest) no vínculo do aparelho
     * e no refresh do token. Desligada, o app segue funcionando no Expo Go (que não é o app Horus
     * e não passa na atestação); ligada, só o build oficial entra. docs/67 §5.3.
     */
    'atestacao_obrigatoria' => (bool) env('ATESTACAO_OBRIGATORIA', false),

    // Validade do desafio (nonce) que o app atesta — curto, é usado logo em seguida.
    'desafio_validade_segundos' => 300,

    // Tolerância de relógio na assinatura dos requests (X-Assinatura-Em).
    'assinatura_tolerancia_segundos' => 300,

    'play_integrity' => [
        'pacote' => env('PLAY_INTEGRITY_PACOTE', 'com.prossigatec.horus'),
        'numero_projeto_cloud' => env('PLAY_INTEGRITY_PROJETO', ''),
        // Caminho do JSON da conta de serviço com acesso à Play Integrity API.
        'credenciais' => env('PLAY_INTEGRITY_CREDENCIAIS', storage_path('app/private/play-integrity.json')),
        // Hash SHA-256 (hex, minúsculo, sem ":") do certificado de assinatura do app na Play Store.
        'certificado_sha256' => env('PLAY_INTEGRITY_CERTIFICADO_SHA256', ''),
    ],

    'app_attest' => [
        'team_id' => env('APP_ATTEST_TEAM_ID', ''),
        'bundle_id' => env('APP_ATTEST_BUNDLE_ID', 'com.prossigatec.horus'),
        // production | development (aaguid "appattest" vs "appattestdevelop").
        'ambiente' => env('APP_ATTEST_AMBIENTE', 'production'),
        // Apple App Attestation Root CA (PEM), baixado de
        // https://www.apple.com/certificateauthority/Apple_App_Attestation_Root_CA.pem
        'raiz' => env('APP_ATTEST_RAIZ', storage_path('app/private/Apple_App_Attestation_Root_CA.pem')),
    ],
];
