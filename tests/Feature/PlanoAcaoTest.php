<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\PlanoAcaoHistorico;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/37-PLANOS-DE-ACAO.md — MVP (§8 fase 1): plano nasce de um alerta, etapas manuais,
 * histórico append-only, permissões dedicadas por ação.
 */
class PlanoAcaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
    }

    /** @return array{0: string, 1: string} [visitaUuid, registroUuid] */
    private function criarAlerta(bool $ehAlerta = true): array
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_alerta' => $ehAlerta]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid, 'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->json('visita.id');
        $registroUuid = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid, 'observacao' => 'Gôndola vazia',
        ])->json('registro.id');

        return [$visitaUuid, $registroUuid];
    }

    private function payload(string $registroUuid, array $etapas = []): array
    {
        return [
            'registro_uuid' => $registroUuid,
            'titulo' => 'Reposição — Loja Centro',
            'etapas' => $etapas ?: [
                ['titulo' => 'Comunicar o vendedor', 'responsavel_uuid' => $this->admin->uuid],
                ['titulo' => 'Vendedor visita a loja', 'responsavel_externo_nome' => 'Carlos Andrade'],
                ['titulo' => 'Pedido faturado', 'evidencia_obrigatoria' => true],
            ],
        ];
    }

    private function gestorCom(array $permissoes): Usuario
    {
        $perfil = Perfil::factory()->comPermissoes(array_map(fn (Permissao $p) => $p->value, $permissoes))
            ->create(['empresa_id' => $this->empresa->id]);

        return Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
    }

    private function criarPlano(): array
    {
        [, $registroUuid] = $this->criarAlerta();
        Sanctum::actingAs($this->admin);
        $plano = $this->postJson('/api/planos-acao', $this->payload($registroUuid))->assertCreated()->json('plano_acao');

        return [$plano, $registroUuid];
    }

    public function test_admin_cria_plano_a_partir_de_alerta_com_etapas_e_historico(): void
    {
        [, $registroUuid] = $this->criarAlerta();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/planos-acao', $this->payload($registroUuid));

        $response->assertCreated()
            ->assertJsonPath('plano_acao.status', 'ABERTO')
            ->assertJsonPath('plano_acao.origem_tipo', 'ALERTA')
            ->assertJsonPath('plano_acao.origem.registro_id', $registroUuid)
            ->assertJsonPath('plano_acao.etapas.0.ordem', 1)
            ->assertJsonPath('plano_acao.etapas.0.responsavel.id', $this->admin->uuid)
            ->assertJsonPath('plano_acao.etapas.1.responsavel_externo_nome', 'Carlos Andrade')
            ->assertJsonPath('plano_acao.etapas.2.evidencia_obrigatoria', true)
            ->assertJsonPath('plano_acao.historico.0.acao', 'PLANO_CRIADO')
            ->assertJsonPath('permissoes.concluir', true);
        $this->assertCount(3, $response->json('plano_acao.etapas'));
    }

    public function test_registro_que_nao_e_alerta_e_rejeitado(): void
    {
        [, $registroUuid] = $this->criarAlerta(ehAlerta: false);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/planos-acao', $this->payload($registroUuid))
            ->assertUnprocessable()->assertJsonValidationErrors('registro_uuid');
    }

    public function test_so_um_plano_ativo_por_alerta_e_devolve_o_existente(): void
    {
        [$plano, $registroUuid] = $this->criarPlano();

        $this->postJson('/api/planos-acao', $this->payload($registroUuid))
            ->assertUnprocessable()
            ->assertJsonPath('plano_acao_id', $plano['id']);
    }

    public function test_reincidencia_apos_cancelar_vira_plano_novo(): void
    {
        [$plano, $registroUuid] = $this->criarPlano();
        $this->postJson("/api/planos-acao/{$plano['id']}/cancelar", ['motivo' => 'Aberto por engano'])->assertOk();

        $novo = $this->postJson('/api/planos-acao', $this->payload($registroUuid))->assertCreated()->json('plano_acao.id');

        $this->assertNotSame($plano['id'], $novo);
    }

    public function test_movimentar_etapa_inicia_plano_e_registra_historico_com_ator_externo(): void
    {
        [$plano] = $this->criarPlano();
        $etapaExterna = $plano['etapas'][1]['id'];

        $response = $this->postJson("/api/planos-acao/{$plano['id']}/etapas/{$etapaExterna}/status", ['status' => 'FEITA']);

        $response->assertOk()
            ->assertJsonPath('plano_acao.status', 'EM_ANDAMENTO')
            ->assertJsonPath('plano_acao.etapas.1.status', 'FEITA')
            ->assertJsonPath('plano_acao.etapas.1.feita_por.id', $this->admin->uuid);
        $this->assertStringContainsString('acompanhando Carlos Andrade', $response->json('plano_acao.historico.0.descricao'));
    }

    public function test_etapa_com_evidencia_obrigatoria_exige_texto_ou_anexo(): void
    {
        Storage::fake(config('filesystems.default'));
        [$plano] = $this->criarPlano();
        $etapa = $plano['etapas'][2]['id'];
        $url = "/api/planos-acao/{$plano['id']}/etapas/{$etapa}/status";

        $this->postJson($url, ['status' => 'FEITA'])->assertUnprocessable()->assertJsonValidationErrors('evidencia_texto');

        $response = $this->post($url, [
            'status' => 'FEITA',
            'evidencia_texto' => 'NF 12345',
            // PDF em vez de image(): GD não está habilitado neste ambiente.
            'evidencia_arquivo' => UploadedFile::fake()->create('nf.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('plano_acao.etapas.2.evidencia_texto', 'NF 12345');
        $this->assertNotNull($response->json('plano_acao.etapas.2.evidencia_arquivo_url'));
        $this->get($response->json('plano_acao.etapas.2.evidencia_arquivo_url'))->assertOk();
    }

    public function test_bloquear_e_cancelar_etapa_exigem_motivo_e_feita_e_terminal(): void
    {
        [$plano] = $this->criarPlano();
        $etapa = $plano['etapas'][0]['id'];
        $url = "/api/planos-acao/{$plano['id']}/etapas/{$etapa}/status";

        $this->postJson($url, ['status' => 'BLOQUEADA'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->postJson($url, ['status' => 'BLOQUEADA', 'motivo' => 'Vendedor não foi'])
            ->assertOk()->assertJsonPath('plano_acao.etapas.0.motivo', 'Vendedor não foi');
        $this->postJson($url, ['status' => 'FEITA'])->assertOk();

        $this->postJson($url, ['status' => 'PENDENTE'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_concluir_exige_etapas_finalizadas_e_resolve_o_alerta(): void
    {
        [$plano, $registroUuid] = $this->criarPlano();
        $base = "/api/planos-acao/{$plano['id']}";

        $this->postJson("{$base}/concluir")->assertUnprocessable();

        $this->postJson("{$base}/etapas/{$plano['etapas'][0]['id']}/status", ['status' => 'FEITA'])->assertOk();
        $this->postJson("{$base}/etapas/{$plano['etapas'][1]['id']}/status", ['status' => 'CANCELADA', 'motivo' => 'Pedido direto no ERP'])->assertOk();
        $this->postJson("{$base}/etapas/{$plano['etapas'][2]['id']}/status", ['status' => 'FEITA', 'evidencia_texto' => 'NF 1'])->assertOk();

        $this->postJson("{$base}/concluir")->assertOk()
            ->assertJsonPath('plano_acao.status', 'CONCLUIDO')
            ->assertJsonPath('plano_acao.concluido_por.id', $this->admin->uuid)
            ->assertJsonPath('plano_acao.origem.registro_id', $registroUuid);
        $this->assertNotNull($this->getJson($base)->json('plano_acao.origem.alerta_resolvido_em'));

        // Plano fechado não aceita mais movimentação.
        $this->postJson("{$base}/etapas", ['titulo' => 'Mais uma'])->assertUnprocessable();
    }

    public function test_adicionar_etapa_vai_pro_fim_da_fila(): void
    {
        [$plano] = $this->criarPlano();

        $this->postJson("/api/planos-acao/{$plano['id']}/etapas", ['titulo' => 'Conferir gôndola', 'prazo' => now()->addDays(2)->toDateString()])
            ->assertOk()
            ->assertJsonPath('plano_acao.etapas.3.ordem', 4)
            ->assertJsonPath('plano_acao.etapas.3.titulo', 'Conferir gôndola')
            ->assertJsonPath('plano_acao.historico.0.acao', 'ETAPA_ADICIONADA');
    }

    public function test_etapa_com_prazo_vencido_deixa_plano_atrasado_e_entra_no_filtro(): void
    {
        [, $registroUuid] = $this->criarAlerta();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/planos-acao', $this->payload($registroUuid, [
            ['titulo' => 'Atrasada', 'prazo' => now()->subDays(2)->toDateString()],
        ]))->assertCreated()->assertJsonPath('plano_acao.atrasado', true)->assertJsonPath('plano_acao.etapas.0.atrasada', true);

        $this->getJson('/api/planos-acao?atrasados=1')->assertOk()
            ->assertJsonCount(1, 'planos_acao')
            ->assertJsonPath('resumo.atrasados', 1)
            ->assertJsonPath('resumo.ativos', 1);
    }

    public function test_permissoes_sao_separadas_por_acao(): void
    {
        [$plano] = $this->criarPlano();
        $etapa = $plano['etapas'][0]['id'];

        // Só visualiza: lê, mas não cria/movimenta/conclui.
        Sanctum::actingAs($this->gestorCom([Permissao::PLANOS_ACAO_VISUALIZAR]));
        $this->getJson("/api/planos-acao/{$plano['id']}")->assertOk()
            ->assertJsonPath('permissoes.movimentar_etapa', false)
            ->assertJsonPath('permissoes.concluir', false);
        $this->postJson("/api/planos-acao/{$plano['id']}/etapas/{$etapa}/status", ['status' => 'FEITA'])->assertForbidden();

        // "Operacional": movimenta etapa, mas não fecha o plano (§4.7).
        Sanctum::actingAs($this->gestorCom([Permissao::PLANOS_ACAO_VISUALIZAR, Permissao::PLANOS_ACAO_MOVIMENTAR_ETAPA]));
        $this->postJson("/api/planos-acao/{$plano['id']}/etapas/{$etapa}/status", ['status' => 'FEITA'])->assertOk();
        $this->postJson("/api/planos-acao/{$plano['id']}/concluir")->assertForbidden();
        $this->postJson("/api/planos-acao/{$plano['id']}/cancelar", ['motivo' => 'x'])->assertForbidden();

        // Sem nenhuma planos_acao.*: nem lista.
        Sanctum::actingAs($this->gestorCom([Permissao::CATALOGO_GERENCIAR]));
        $this->getJson('/api/planos-acao')->assertForbidden();

        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/planos-acao')->assertForbidden();
    }

    public function test_plano_de_outra_empresa_nao_e_visivel(): void
    {
        [$plano] = $this->criarPlano();
        $outraEmpresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]));

        $this->getJson("/api/planos-acao/{$plano['id']}")->assertNotFound();
        $this->getJson('/api/planos-acao')->assertOk()->assertJsonCount(0, 'planos_acao');
    }

    public function test_alerta_de_outra_empresa_nao_pode_originar_plano(): void
    {
        [, $registroUuid] = $this->criarAlerta();
        $outraEmpresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]));

        $this->postJson('/api/planos-acao', $this->payload($registroUuid, [['titulo' => 'X']]))
            ->assertUnprocessable()->assertJsonValidationErrors('registro_uuid');
    }

    public function test_historico_e_append_only(): void
    {
        [$plano] = $this->criarPlano();
        $this->postJson("/api/planos-acao/{$plano['id']}/etapas/{$plano['etapas'][0]['id']}/status", ['status' => 'EM_ANDAMENTO'])->assertOk();
        $this->postJson("/api/planos-acao/{$plano['id']}/etapas/{$plano['etapas'][0]['id']}/status", ['status' => 'FEITA'])->assertOk();

        $this->assertSame(3, PlanoAcaoHistorico::count());
        $this->assertNull(PlanoAcaoHistorico::first()->updated_at ?? null);
    }

    public function test_plano_de_alerta_herda_a_loja_do_alerta(): void
    {
        [$plano] = $this->criarPlano();

        $this->assertSame($plano['origem']['ponto_venda']['id'], $plano['ponto_venda']['id']);
        $this->assertNull($plano['rede_loja']);
    }

    public function test_plano_livre_sem_vinculo(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/planos-acao', [
            'titulo' => 'Revisar política de bonificação',
            'etapas' => [['titulo' => 'Levantar contratos vigentes']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('plano_acao.origem_tipo', 'LIVRE')
            ->assertJsonPath('plano_acao.origem', null)
            ->assertJsonPath('plano_acao.ponto_venda', null)
            ->assertJsonPath('plano_acao.rede_loja', null)
            ->assertJsonPath('plano_acao.historico.0.descricao', 'Plano criado com 1 etapa');

        // Plano livre fecha normalmente, sem alerta nenhum pra resolver.
        $id = $response->json('plano_acao.id');
        $this->postJson("/api/planos-acao/{$id}/etapas/{$response->json('plano_acao.etapas.0.id')}/status", ['status' => 'FEITA'])->assertOk();
        $this->postJson("/api/planos-acao/{$id}/concluir")->assertOk()->assertJsonPath('plano_acao.status', 'CONCLUIDO');
    }

    public function test_plano_livre_por_loja_ou_por_rede_e_filtros(): void
    {
        $rede = RedeLoja::factory()->create(['empresa_id' => $this->empresa->id]);
        $lojaDaRede = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'rede_loja_id' => $rede->id]);
        $outraLoja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->admin);
        $etapas = [['titulo' => 'Negociar']];

        $planoRede = $this->postJson('/api/planos-acao', ['titulo' => 'Rede', 'rede_loja_uuid' => $rede->uuid, 'etapas' => $etapas])
            ->assertCreated()
            ->assertJsonPath('plano_acao.rede_loja.id', $rede->uuid)
            ->assertJsonPath('plano_acao.ponto_venda', null)
            ->json('plano_acao.id');
        $planoLoja = $this->postJson('/api/planos-acao', ['titulo' => 'Loja da rede', 'ponto_venda_uuid' => $lojaDaRede->uuid, 'etapas' => $etapas])
            ->assertCreated()
            ->assertJsonPath('plano_acao.ponto_venda.id', $lojaDaRede->uuid)
            ->assertJsonPath('plano_acao.ponto_venda.rede.id', $rede->uuid)
            ->json('plano_acao.id');
        $planoOutra = $this->postJson('/api/planos-acao', ['titulo' => 'Outra', 'ponto_venda_uuid' => $outraLoja->uuid, 'etapas' => $etapas])
            ->assertCreated()->json('plano_acao.id');

        // Rede = plano da rede + plano de loja dessa rede.
        $daRede = collect($this->getJson("/api/planos-acao?rede_loja_uuid={$rede->uuid}")->assertOk()->json('planos_acao'))->pluck('id');
        $this->assertEqualsCanonicalizing([$planoRede, $planoLoja], $daRede->all());

        $daLoja = collect($this->getJson("/api/planos-acao?ponto_venda_uuid={$outraLoja->uuid}")->json('planos_acao'))->pluck('id');
        $this->assertSame([$planoOutra], $daLoja->all());

        $this->getJson('/api/planos-acao?origem_tipo=LIVRE')->assertJsonCount(3, 'planos_acao');
    }

    public function test_loja_e_rede_juntas_ou_alerta_com_loja_sao_rejeitados(): void
    {
        $rede = RedeLoja::factory()->create(['empresa_id' => $this->empresa->id]);
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        [, $registroUuid] = $this->criarAlerta();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/planos-acao', [
            'titulo' => 'X', 'ponto_venda_uuid' => $loja->uuid, 'rede_loja_uuid' => $rede->uuid, 'etapas' => [['titulo' => 'a']],
        ])->assertUnprocessable()->assertJsonValidationErrors('ponto_venda_uuid');

        $this->postJson('/api/planos-acao', [
            'titulo' => 'X', 'registro_uuid' => $registroUuid, 'ponto_venda_uuid' => $loja->uuid, 'etapas' => [['titulo' => 'a']],
        ])->assertUnprocessable()->assertJsonValidationErrors('registro_uuid');
    }

    public function test_loja_de_outra_empresa_e_rejeitada(): void
    {
        $lojaAlheia = PontoVenda::factory()->create(['empresa_id' => Empresa::factory()->create()->id]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/planos-acao', ['titulo' => 'X', 'ponto_venda_uuid' => $lojaAlheia->uuid, 'etapas' => [['titulo' => 'a']]])
            ->assertUnprocessable()->assertJsonValidationErrors('ponto_venda_uuid');
    }

    public function test_painel_de_atividades_expoe_o_plano_ativo_do_alerta(): void
    {
        [$plano] = $this->criarPlano();

        $eventos = $this->getJson('/api/atividades')->assertOk()->json('eventos');
        $alerta = collect($eventos)->firstWhere('tipo_evento', 'ALERTA');

        $this->assertSame($plano['id'], $alerta['registro']['plano_acao_ativo']['id']);
    }
}
