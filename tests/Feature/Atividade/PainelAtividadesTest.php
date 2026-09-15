<?php

namespace Tests\Feature\Atividade;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Painel de Atividades — feed único (check-in/checkout/alertas) pra ADMIN/GESTOR acompanhar as
 * visitas do dia, substituto do grupo de WhatsApp. Ver AtividadeController e
 * docs/17-PAINEL-ATIVIDADES.md.
 */
class PainelAtividadesTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisita(Usuario $promotor, PontoVenda $pdv): string
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    private function criarTipoAlerta(Empresa $empresa, string $descricao = 'Ruptura crítica'): TipoRegistro
    {
        return TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => $descricao, 'eh_alerta' => true,
        ]);
    }

    private function criarRegistro(string $visitaUuid, string $tipoUuid): string
    {
        return $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Teste',
        ])->json('registro.id');
    }

    public function test_promotor_nao_acessa_o_painel(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/atividades')->assertForbidden();
    }

    public function test_gestor_ve_checkin_e_checkout_no_feed(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->assertOk();

        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $response = $this->getJson('/api/atividades')->assertOk();
        $eventos = collect($response->json('eventos'));

        $inicio = $eventos->firstWhere('tipo_evento', 'VISITA_INICIADA');
        $fim = $eventos->firstWhere('tipo_evento', 'VISITA_FINALIZADA');

        $this->assertNotNull($inicio);
        $this->assertNotNull($fim);
        $this->assertSame((float) $pdv->latitude, $inicio['localizacao']['latitude']);
        $this->assertSame((float) $pdv->longitude, $inicio['localizacao']['longitude']);
        $this->assertSame([], $fim['imagens']);
    }

    public function test_alerta_aparece_no_feed_com_o_registro_aninhado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/atividades')->assertOk();
        $alerta = collect($response->json('eventos'))->firstWhere('tipo_evento', 'ALERTA');

        $this->assertNotNull($alerta);
        $this->assertSame($registroUuid, $alerta['registro']['id']);
        $this->assertNull($alerta['registro']['alerta_resolvido_em']);
    }

    public function test_registro_de_tipo_sem_eh_alerta_nao_aparece_como_alerta(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoComum = TipoRegistro::where('empresa_id', $empresa->id)->where('descricao', 'Observação')->first();
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($visitaUuid, $tipoComum->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/atividades')->assertOk();
        $this->assertFalse(collect($response->json('eventos'))->contains('tipo_evento', 'ALERTA'));
    }

    public function test_filtro_por_tipo_de_registro_especifico_suprime_checkin_e_checkout(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoPontoExtra = $this->criarTipoAlerta($empresa, 'Ponto extra');
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($visitaUuid, $tipoPontoExtra->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/atividades?tipo_registro_uuid={$tipoPontoExtra->uuid}")->assertOk();
        $eventos = collect($response->json('eventos'));

        $this->assertTrue($eventos->every(fn ($e) => $e['tipo_evento'] === 'ALERTA'));
        $this->assertCount(1, $eventos);
    }

    public function test_filtro_pendentes_so_mostra_alertas_nao_resolvidos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroResolvidoUuid = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroResolvidoUuid}/resolver-alerta")->assertOk();

        $response = $this->getJson('/api/atividades?pendentes=1')->assertOk();
        $eventos = collect($response->json('eventos'));

        $this->assertCount(1, $eventos);
        $this->assertNotSame($registroResolvidoUuid, $eventos->first()['registro']['id']);
    }

    public function test_pagina_o_feed_em_paginas_de_20(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // 25 alertas + 1 check-in (abrirVisita) = 26 eventos no total, sem filtro nenhum.
        for ($i = 0; $i < 25; $i++) {
            $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        }

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $pagina1 = $this->getJson('/api/atividades')->assertOk();
        $this->assertCount(20, $pagina1->json('eventos'));
        $this->assertSame(1, $pagina1->json('meta.current_page'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));
        $this->assertSame(26, $pagina1->json('meta.total'));
        $this->assertSame(20, $pagina1->json('meta.per_page'));

        $pagina2 = $this->getJson('/api/atividades?page=2')->assertOk();
        $this->assertCount(6, $pagina2->json('eventos'));
        $this->assertSame(2, $pagina2->json('meta.current_page'));
        $this->assertSame(26, $pagina2->json('meta.total'));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresaA->id]);
        $this->abrirVisita($promotorA, $pdvA);

        $adminB = Usuario::factory()->admin()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($adminB);

        $response = $this->getJson('/api/atividades')->assertOk();
        $this->assertCount(0, $response->json('eventos'));
    }
}
