<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Acesso à documentação da API (dedoc/scramble, /docs/api) fora do APP_ENV=local — gate
 * `viewApiDocs` controlado por API_DOCS_PUBLICA (config scramble.publica).
 */
class ApiDocsTest extends TestCase
{
    public function test_documentacao_fechada_por_padrao(): void
    {
        config(['scramble.publica' => false]);

        $this->get('/docs/api')->assertForbidden();
    }

    public function test_documentacao_aberta_quando_ligada(): void
    {
        config(['scramble.publica' => true]);

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk()->assertJsonStructure(['openapi', 'paths']);
    }
}
