<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Usuario;
use App\Support\ContextoSentry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Sentry\Event;
use Sentry\State\HubInterface;
use Tests\TestCase;

/** Sentry em homologação (docs/70): desligado sem DSN, e quando liga não leva dado pessoal. */
class Doc70SentryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_dsn_o_sdk_fica_desligado_e_nao_atrapalha_a_aplicacao(): void
    {
        $this->assertNull(config('sentry.dsn'), 'ambiente local/teste não pode ter DSN');
        $this->assertNull($this->app->make(HubInterface::class)->getClient()?->getOptions()->getDsn());

        Route::get('/api/_teste-erro', fn () => throw new \RuntimeException('falha de teste'));

        // A exceção segue virando 500 em JSON, como antes da integração.
        $this->getJson('/api/_teste-erro')->assertStatus(500);
    }

    public function test_evento_leva_uuid_tipo_e_empresa_mas_nunca_email_ou_nome(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create([
            'empresa_id' => $empresa->id,
            'nome' => 'Fulana de Tal',
            'email' => 'fulana@exemplo.com',
        ]);
        Sanctum::actingAs($admin);

        $evento = ContextoSentry::beforeSend(Event::createEvent());

        $this->assertSame($admin->uuid, $evento->getUser()?->getId());
        $this->assertNull($evento->getUser()?->getEmail());
        $this->assertNull($evento->getUser()?->getUsername());
        $this->assertSame((string) $empresa->id, $evento->getTags()['empresa_id']);
        $this->assertArrayHasKey('user_type', $evento->getTags());
        $this->assertStringNotContainsString('fulana@exemplo.com', json_encode($evento->getTags()));
    }

    public function test_sem_usuario_logado_o_evento_sai_sem_contexto_de_usuario(): void
    {
        $evento = ContextoSentry::beforeSend(Event::createEvent());

        $this->assertNull($evento->getUser());
        $this->assertArrayNotHasKey('empresa_id', $evento->getTags());
    }

    public function test_pii_e_tracing_ficam_desligados_por_padrao(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertNull(config('sentry.traces_sample_rate'));
        $this->assertNull(config('sentry.profiles_sample_rate'));
        $this->assertFalse(config('sentry.enable_metrics'));
    }
}
