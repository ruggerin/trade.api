<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\ContratoMeta;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/09-CONTRATO-METAS.md — metas de contrapartida comercial (verba de trade marketing)
 * negociadas dentro de um Contrato.
 */
class ContratoMetaTest extends TestCase
{
    use RefreshDatabase;

    private function criarContrato(Empresa $empresa): Contrato
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        return Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
    }

    public function test_admin_cria_meta_geral_sem_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'descricao' => 'Giro geral da loja no verão',
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta.marca', null)
            ->assertJsonPath('meta.resumo.status_apuracao', 'AGUARDANDO');
        $this->assertDatabaseHas('contrato_metas', ['contrato_id' => $contrato->id, 'marca_id' => null]);
    }

    public function test_admin_cria_meta_vinculada_a_uma_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        $marca = MarcaAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Panasonic']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'marca_uuid' => $marca->uuid,
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ]);

        $response->assertCreated()->assertJsonPath('meta.marca.descricao', 'Panasonic');
    }

    public function test_nao_aceita_marca_de_outra_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        $marcaAlheia = MarcaAuditoria::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'marca_uuid' => $marcaAlheia->uuid,
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ])->assertStatus(422)->assertJsonValidationErrors('marca_uuid');
    }

    public function test_fonte_compartilhado_exige_percentual_industria(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($admin);

        $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'COMPARTILHADO',
        ])->assertStatus(422)->assertJsonValidationErrors('percentual_industria');

        // EMPRESA não aceita percentual_industria.
        $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
            'percentual_industria' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors('percentual_industria');
    }

    public function test_resumo_calcula_split_de_investimento_por_fonte_de_pagamento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($admin);

        $compartilhada = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'COMPARTILHADO',
            'percentual_industria' => 40,
        ])->assertCreated();

        $this->assertEquals(400.0, $compartilhada->json('meta.resumo.valor_investimento_industria'));
        $this->assertEquals(600.0, $compartilhada->json('meta.resumo.valor_investimento_empresa'));

        $daIndustria = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'INDUSTRIA',
        ])->assertCreated();

        $this->assertEquals(1000.0, $daIndustria->json('meta.resumo.valor_investimento_industria'));
        $this->assertEquals(0.0, $daIndustria->json('meta.resumo.valor_investimento_empresa'));
    }

    public function test_lancar_resultado_calcula_status_e_retorno(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($admin);

        $metaUuid = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ])->json('meta.id');

        // Abaixo da meta: NAO_ATINGIDA.
        $abaixo = $this->putJson("/api/contratos/{$contrato->uuid}/metas/{$metaUuid}", [
            'resultado_apurado' => 5000,
        ])->assertOk();
        $this->assertEquals('NAO_ATINGIDA', $abaixo->json('meta.resumo.status_apuracao'));
        $this->assertEquals(5.0, $abaixo->json('meta.resumo.retorno_sobre_investimento'));
        $this->assertNotNull($abaixo->json('meta.apurado_em'));
        $this->assertEquals($admin->uuid, $abaixo->json('meta.apurado_por.id'));

        // Corrige pra cima da meta: ATINGIDA. resultado_apurado já não era null, então
        // apurado_em/apurado_por não mudam de novo (carimbo só na primeira vez).
        $primeiroApuradoEm = $abaixo->json('meta.apurado_em');
        $acima = $this->putJson("/api/contratos/{$contrato->uuid}/metas/{$metaUuid}", [
            'resultado_apurado' => 12000,
        ])->assertOk();
        $this->assertEquals('ATINGIDA', $acima->json('meta.resumo.status_apuracao'));
        $this->assertEquals(12.0, $acima->json('meta.resumo.retorno_sobre_investimento'));
        $this->assertEquals($primeiroApuradoEm, $acima->json('meta.apurado_em'));
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($gestor);

        $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ])->assertForbidden();
    }

    public function test_promotor_nunca_acessa_metas_de_contrato(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ])->assertForbidden();
    }

    public function test_superadmin_cria_e_edita_meta_mas_nao_deleta(): void
    {
        $empresa = Empresa::factory()->create();
        $contrato = $this->criarContrato($empresa);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $metaUuid = $this->postJson("/api/contratos/{$contrato->uuid}/metas", [
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ])->assertCreated()->json('meta.id');

        $this->putJson("/api/contratos/{$contrato->uuid}/metas/{$metaUuid}", [
            'resultado_apurado' => 500,
        ])->assertOk();

        $this->deleteJson("/api/contratos/{$contrato->uuid}/metas/{$metaUuid}")->assertForbidden();
    }

    public function test_admin_remove_meta(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        $meta = ContratoMeta::create([
            'contrato_id' => $contrato->id,
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/contratos/{$contrato->uuid}/metas/{$meta->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('contrato_metas', ['id' => $meta->id]);
    }

    public function test_meta_de_outro_contrato_retorna_404(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contratoA = $this->criarContrato($empresa);
        $contratoB = $this->criarContrato($empresa);
        $metaDoA = ContratoMeta::create([
            'contrato_id' => $contratoA->id,
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/contratos/{$contratoB->uuid}/metas/{$metaDoA->uuid}", [
            'resultado_apurado' => 500,
        ])->assertNotFound();
        $this->deleteJson("/api/contratos/{$contratoB->uuid}/metas/{$metaDoA->uuid}")->assertNotFound();
    }

    public function test_get_contratos_so_traz_metas_quando_pedido_explicitamente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $contrato = $this->criarContrato($empresa);
        ContratoMeta::create([
            'contrato_id' => $contrato->id,
            'valor_investimento' => 1000,
            'meta_valor' => 10000,
            'periodo_inicio' => '2026-01-01',
            'periodo_fim' => '2026-03-31',
            'fonte_pagamento' => 'EMPRESA',
        ]);
        Sanctum::actingAs($admin);

        $semMetas = $this->getJson('/api/contratos')->assertOk();
        $this->assertArrayNotHasKey('metas', $semMetas->json('contratos.0'));

        $comMetas = $this->getJson('/api/contratos?with_metas=1')->assertOk();
        $this->assertCount(1, $comMetas->json('contratos.0.metas'));
    }
}
