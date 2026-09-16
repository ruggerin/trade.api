<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md — molde que gera OrdemServico em massa, uma por PDV
 * elegível, cada uma já carregando os formulários exigidos (obrigatorio/
 * calcula_percentual_compliance vivem no vínculo, não no TipoRegistro).
 */
class DirecionamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_sem_filtro_gera_os_para_todos_pdvs_ativos_da_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv2 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvInativo = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'ativo' => false]);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Expositor Panasonic']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Dia dos Pais',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'formularios' => [
                ['tipo_registro_uuid' => $formulario->uuid, 'obrigatorio' => true, 'calcula_percentual_compliance' => true],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('direcionamento.descricao', 'Dia dos Pais')
            ->assertJsonPath('direcionamento.formularios.0.obrigatorio', true)
            ->assertJsonPath('direcionamento.formularios.0.calcula_percentual_compliance', true)
            ->assertJsonPath('progresso.ordens_geradas', 2);

        $this->assertDatabaseHas('ordens_servico', ['ponto_venda_id' => $pdv1->id, 'origem' => 'DIRECIONAMENTO']);
        $this->assertDatabaseHas('ordens_servico', ['ponto_venda_id' => $pdv2->id, 'origem' => 'DIRECIONAMENTO']);
        $this->assertDatabaseMissing('ordens_servico', ['ponto_venda_id' => $pdvInativo->id]);
    }

    public function test_filtro_de_rede_restringe_geracao_so_as_lojas_daquela_rede(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $redeAlvo = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $outraRede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $pdvNaRede = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'rede_loja_id' => $redeAlvo->id]);
        $pdvForaDaRede = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'rede_loja_id' => $outraRede->id]);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Auditoria Linha Amaciante']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Rede X',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'rede_loja_uuids' => [$redeAlvo->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ]);

        $response->assertCreated()->assertJsonPath('progresso.ordens_geradas', 1);
        $this->assertDatabaseHas('ordens_servico', ['ponto_venda_id' => $pdvNaRede->id]);
        $this->assertDatabaseMissing('ordens_servico', ['ponto_venda_id' => $pdvForaDaRede->id]);
    }

    public function test_filtro_de_promotor_direciona_a_os_ja_pra_ele(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorAlvo = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach([$promotorAlvo->id, $outroPromotor->id]);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Promotor X',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'promotor_uuids' => [$promotorAlvo->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ordens_servico', [
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotorAlvo->id,
        ]);
    }

    public function test_responder_formulario_marca_respondido_em_e_atualiza_progresso(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist Loja']);
        Sanctum::actingAs($admin);

        $criar = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Única Loja',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'ponto_venda_uuids' => [$pdv->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ])->assertCreated();
        $direcionamentoUuid = $criar->json('direcionamento.id');

        $ordemServico = OrdemServico::where('ponto_venda_id', $pdv->id)->firstOrFail();

        Sanctum::actingAs($promotor);

        $checkin = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'ordem_servico_uuid' => $ordemServico->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertCreated();
        $visitaUuid = $checkin->json('visita.id');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $formulario->uuid,
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $detalhe = $this->getJson("/api/direcionamentos/{$direcionamentoUuid}")->assertOk();
        $detalhe->assertJsonPath('progresso.ordens_geradas', 1)
            ->assertJsonPath('progresso.por_formulario.0.expedidos', 1)
            ->assertJsonPath('progresso.por_formulario.0.preenchidos', 1);
    }

    public function test_cancelar_direcionamento_cancela_em_cascata_so_os_pendente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdvPendente = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvEmAndamento = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvEmAndamento->promotores()->attach($promotor->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist']);
        Sanctum::actingAs($admin);

        $criar = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação a cancelar',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ])->assertCreated();
        $direcionamentoUuid = $criar->json('direcionamento.id');

        // Uma das duas OS geradas começa a ser atendida antes do cancelamento.
        $osEmAndamento = OrdemServico::where('ponto_venda_id', $pdvEmAndamento->id)->firstOrFail();
        Sanctum::actingAs($promotor);
        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdvEmAndamento->uuid,
            'ordem_servico_uuid' => $osEmAndamento->uuid,
            'latitude' => $pdvEmAndamento->latitude,
            'longitude' => $pdvEmAndamento->longitude,
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $this->putJson("/api/direcionamentos/{$direcionamentoUuid}", ['ativo' => false])->assertOk();

        $this->assertDatabaseHas('ordens_servico', [
            'ponto_venda_id' => $pdvPendente->id, 'status' => 'CANCELADA',
        ]);
        $this->assertDatabaseHas('ordens_servico', [
            'ponto_venda_id' => $pdvEmAndamento->id, 'status' => 'EM_ANDAMENTO',
        ]);
    }

    public function test_isolado_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        $formularioB = TipoRegistro::create(['empresa_id' => $empresaB->id, 'descricao' => 'Formulário B']);
        Sanctum::actingAs($adminA);

        // Tenta usar um formulário de outra empresa — a validação de existência já bloqueia.
        $this->postJson('/api/direcionamentos', [
            'descricao' => 'Tentativa cross-empresa',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'formularios' => [['tipo_registro_uuid' => $formularioB->uuid]],
        ])->assertUnprocessable();

        $direcionamentoB = \App\Models\Direcionamento::create([
            'empresa_id' => $empresaB->id, 'descricao' => 'Da empresa B',
            'vigencia_inicio' => now(), 'vigencia_fim' => now()->addDays(10),
        ]);

        $this->getJson("/api/direcionamentos/{$direcionamentoB->uuid}")->assertNotFound();
    }

    public function test_promotor_sem_permissao_recebe_403(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/direcionamentos', [
            'descricao' => 'Não devia dar certo',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'formularios' => [],
        ])->assertForbidden();
    }

    public function test_ordem_servico_manual_com_formulario_vinculado_direto_sem_direcionamento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Auditoria pontual']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ordens-servico', [
            'ponto_venda_uuid' => $pdv->uuid,
            'prazo_inicio' => now()->toDateTimeString(),
            'prazo_fim' => now()->addDay()->toDateTimeString(),
            'formularios' => [
                ['tipo_registro_uuid' => $formulario->uuid, 'obrigatorio' => true],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('ordem_servico.origem', 'MANUAL')
            ->assertJsonPath('ordem_servico.direcionamento', null)
            ->assertJsonPath('ordem_servico.formularios.0.tipo_registro.id', $formulario->uuid)
            ->assertJsonPath('ordem_servico.formularios.0.obrigatorio', true);
    }

    public function test_busca_individual_expoe_formularios_pendentes_pro_mobile(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorAlvo = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotorAlvo->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist']);
        Sanctum::actingAs($admin);

        $criar = $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação individual',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'ponto_venda_uuids' => [$pdv->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ])->assertCreated();
        $ordemServicoUuid = OrdemServico::where('ponto_venda_id', $pdv->id)->firstOrFail()->uuid;

        // Dono da OS acessa normalmente.
        Sanctum::actingAs($promotorAlvo);
        $this->getJson("/api/ordens-servico/{$ordemServicoUuid}")
            ->assertOk()
            ->assertJsonPath('ordem_servico.direcionamento.descricao', 'Ação individual')
            ->assertJsonPath('ordem_servico.formularios.0.tipo_registro.descricao', 'Checklist')
            ->assertJsonPath('ordem_servico.formularios.0.respondido_em', null);

        // Outro promotor, sem vínculo com essa OS destinada especificamente ao promotorAlvo, recebe 403.
        Sanctum::actingAs($outroPromotor);
        $this->getJson("/api/ordens-servico/{$ordemServicoUuid}")->assertForbidden();
    }

    public function test_cancelar_em_lote_so_cancela_as_pendentes(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv2 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $os1 = OrdemServico::create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv1->id, 'origem' => 'MANUAL',
            'obrigatoria' => true, 'prazo_inicio' => now(), 'prazo_fim' => now()->addDay(), 'status' => 'PENDENTE',
        ]);
        $os2 = OrdemServico::create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv2->id, 'origem' => 'MANUAL',
            'obrigatoria' => true, 'prazo_inicio' => now(), 'prazo_fim' => now()->addDay(), 'status' => 'CONCLUIDA',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ordens-servico/cancelar-em-lote', [
            'uuids' => [$os1->uuid, $os2->uuid],
        ]);

        $response->assertOk()->assertJsonPath('canceladas', 1);
        $this->assertDatabaseHas('ordens_servico', ['id' => $os1->id, 'status' => 'CANCELADA']);
        $this->assertDatabaseHas('ordens_servico', ['id' => $os2->id, 'status' => 'CONCLUIDA']);
    }

    public function test_cancelar_registro_reabre_formulario_na_ordem_de_servico(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist Loja']);
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'REGISTRO_CANCELAMENTO_PERMITIDO', 'valor' => 'true']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Única Loja',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'ponto_venda_uuids' => [$pdv->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid]],
        ])->assertCreated();
        $ordemServico = OrdemServico::where('ponto_venda_id', $pdv->id)->firstOrFail();

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'ordem_servico_uuid' => $ordemServico->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        $registroUuid = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $formulario->uuid,
        ])->assertCreated()->json('registro.id');

        $this->assertDatabaseHas('ordem_servico_formularios', [
            'ordem_servico_id' => $ordemServico->id,
        ]);
        $pivotAntes = DB::table('ordem_servico_formularios')
            ->where('ordem_servico_id', $ordemServico->id)->first();
        $this->assertNotNull($pivotAntes->respondido_em);

        // Promotor se arrepende e cancela a resposta — a única resposta válida some, então o
        // formulário tem que voltar a ficar pendente na OS.
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertOk();

        $pivotDepois = DB::table('ordem_servico_formularios')
            ->where('ordem_servico_id', $ordemServico->id)->first();
        $this->assertNull($pivotDepois->respondido_em);
    }

    public function test_checkout_bloqueia_com_formulario_obrigatorio_pendente_quando_parametro_ativo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist Loja']);
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'DIRECIONAMENTO_BLOQUEIA_CHECKOUT', 'valor' => 'true']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Única Loja',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'ponto_venda_uuids' => [$pdv->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid, 'obrigatorio' => true]],
        ])->assertCreated();
        $ordemServico = OrdemServico::where('ponto_venda_id', $pdv->id)->firstOrFail();

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'ordem_servico_uuid' => $ordemServico->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        // Formulário obrigatório ainda pendente — parâmetro ligado bloqueia o checkout com 422.
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertStatus(422);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $formulario->uuid,
        ])->assertCreated();

        // Respondido — agora o checkout passa.
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertOk()->assertJsonPath('visita.status', 'FINALIZADA');
    }

    public function test_checkout_nao_bloqueia_por_padrao_sem_o_parametro_ativo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $formulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Checklist Loja']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/direcionamentos', [
            'descricao' => 'Ação Única Loja',
            'vigencia_inicio' => now()->toDateTimeString(),
            'vigencia_fim' => now()->addDays(15)->toDateTimeString(),
            'ponto_venda_uuids' => [$pdv->uuid],
            'formularios' => [['tipo_registro_uuid' => $formulario->uuid, 'obrigatorio' => true]],
        ])->assertCreated();
        $ordemServico = OrdemServico::where('ponto_venda_id', $pdv->id)->firstOrFail();

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'ordem_servico_uuid' => $ordemServico->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        // Sem o parâmetro ligado, formulário obrigatório pendente só avisa — nunca bloqueia.
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertOk()->assertJsonPath('visita.status', 'FINALIZADA');
    }
}
