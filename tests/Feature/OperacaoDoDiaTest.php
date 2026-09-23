<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint agregador do painel "Operação do Dia" — docs/32-PAINEL-OPERACAO-DO-DIA.md Fase 1.
 */
class OperacaoDoDiaTest extends TestCase
{
    use RefreshDatabase;

    // Congela o relógio ao meio-dia — vários testes usam addHour()/subHour() em cima de "agora"
    // pra simular horário previsto, e perto da meia-noite isso pode virar o dia (ambíguo pra um
    // TIME sem data como `horario_previsto`, ver App\Support\OperacaoDoDia::horarioPrevistoEm).
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarOrdemServico(Empresa $empresa, PontoVenda $pdv, Usuario $promotor, array $atributos = []): OrdemServico
    {
        return OrdemServico::factory()->create(array_merge([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'prazo_fim' => now(),
        ], $atributos));
    }

    public function test_promotor_nao_acessa(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/operacao-do-dia')->assertForbidden();
    }

    public function test_promotor_com_visita_aberta_aparece_como_no_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $os = $this->criarOrdemServico($empresa, $pdv, $promotor, ['status' => StatusOrdemServico::EM_ANDAMENTO]);
        $visita = Visita::factory()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'ordem_servico_id' => $os->id,
            'status' => StatusVisita::ABERTA,
        ]);
        $os->update(['visita_id' => $visita->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertSame('NO_PDV', $linha['status']);
        $this->assertSame($pdv->uuid, $linha['ponto_venda_atual']['id']);
        $this->assertSame(1, $response->json('kpis.em_campo.atual'));
    }

    public function test_todas_as_os_concluidas_sem_visita_aberta_e_encerrado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarOrdemServico($empresa, $pdv, $promotor, ['status' => StatusOrdemServico::CONCLUIDA]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertSame('ENCERRADO', $linha['status']);
        $this->assertSame(1, $response->json('kpis.em_campo.encerrados'));
    }

    public function test_os_pendente_com_horario_previsto_alem_da_tolerancia_e_atrasada(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        // Default 30 min de tolerância — 1h atrás já estoura.
        $this->criarOrdemServico($empresa, $pdv, $promotor, [
            'status' => StatusOrdemServico::PENDENTE,
            'horario_previsto' => now()->subHour()->format('H:i'),
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertSame('ATRASADO', $linha['status']);
        $this->assertSame(1, $response->json('kpis.atrasados'));
        $itemFila = collect($response->json('fila_acoes'))->firstWhere('tipo', 'ATRASO');
        $this->assertNotNull($itemFila);
        $this->assertSame($promotor->uuid, $itemFila['usuario']['id']);
    }

    public function test_os_pendente_dentro_da_tolerancia_e_deslocamento(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarOrdemServico($empresa, $pdv, $promotor, [
            'status' => StatusOrdemServico::PENDENTE,
            'horario_previsto' => now()->addHour()->format('H:i'),
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertSame('DESLOCAMENTO', $linha['status']);
    }

    public function test_tolerancia_de_atraso_e_configuravel_via_parametro(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '5', 'ativo' => true]);
        // 10 minutos de atraso: dentro da tolerância padrão (30min) seria DESLOCAMENTO, mas com
        // a tolerância customizada de 5min já é ATRASADO.
        $this->criarOrdemServico($empresa, $pdv, $promotor, [
            'status' => StatusOrdemServico::PENDENTE,
            'horario_previsto' => now()->subMinutes(10)->format('H:i'),
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertSame('ATRASADO', $linha['status']);
    }

    public function test_jornada_usa_parametro_ou_cai_no_default(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $semParametro = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame(['inicio' => '07:00', 'fim' => '17:00'], $semParametro->json('jornada'));

        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'JORNADA_INICIO', 'valor' => '08:00', 'ativo' => true]);
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'JORNADA_FIM', 'valor' => '18:00', 'ativo' => true]);

        $comParametro = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame(['inicio' => '08:00', 'fim' => '18:00'], $comParametro->json('jornada'));
    }

    public function test_ruptura_aberta_aparece_nos_kpis_por_sku_e_na_fila(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Café torrado 500g']);
        $tipoRuptura = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ruptura', 'eh_alerta' => true]);
        $visita = Visita::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id]);
        VisitaRegistro::create([
            'visita_id' => $visita->id,
            'tipo_registro_id' => $tipoRuptura->id,
            'produto_auditoria_id' => $produto->id,
            'ruptura' => true,
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();

        $this->assertSame(1, $response->json('kpis.rupturas_abertas.total'));
        $this->assertSame(1, $response->json('kpis.rupturas_abertas.pdvs'));
        $porSku = collect($response->json('rupturas_por_sku'));
        $this->assertSame($produto->uuid, $porSku->first()['produto']['id']);
        $this->assertSame(1, $porSku->first()['pdvs']);
        $this->assertNotNull(collect($response->json('fila_acoes'))->firstWhere('tipo', 'ALERTA'));
    }

    public function test_ruptura_resolvida_nao_conta_como_aberta(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $tipoRuptura = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ruptura', 'eh_alerta' => true]);
        $visita = Visita::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id]);
        VisitaRegistro::create([
            'visita_id' => $visita->id,
            'tipo_registro_id' => $tipoRuptura->id,
            'produto_auditoria_id' => $produto->id,
            'ruptura' => true,
            'alerta_resolvido_em' => now(),
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();

        $this->assertSame(0, $response->json('kpis.rupturas_abertas.total'));
        $this->assertSame([], $response->json('rupturas_por_sku'));
    }

    public function test_formularios_emitidos_conta_expedidos_e_preenchidos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoFormulario = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        // PENDENTE de propósito: CheckinVisitaRequest só aceita vincular uma OS ainda pendente
        // ao check-in (vira EM_ANDAMENTO sozinha em VisitaController::store).
        $os = $this->criarOrdemServico($empresa, $pdv, $promotor, ['status' => StatusOrdemServico::PENDENTE]);
        $os->formularios()->attach($tipoFormulario->id, ['obrigatorio' => true]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $antesDeResponder = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame(['expedidos' => 1, 'preenchidos' => 0], $antesDeResponder->json('kpis.formularios_emitidos'));

        // Promotor responde o formulário na visita vinculada — mesmo fluxo real do app mobile
        // (VisitaRegistroController::store marca `respondido_em` na OS automaticamente).
        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->json('visita.id');
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoFormulario->uuid,
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $depoisDeResponder = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame(['expedidos' => 1, 'preenchidos' => 1], $depoisDeResponder->json('kpis.formularios_emitidos'));
    }

    public function test_promotor_sem_sinal_aparece_no_kpi_e_na_fila(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        // Rastreamento::JANELA_ATIVO_MINUTOS é 5 — 10 minutos atrás já é "sem sinal".
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id,
            'ultima_localizacao_em' => now()->subMinutes(10),
            'ultima_localizacao_latitude' => -3.1,
            'ultima_localizacao_longitude' => -60.0,
        ]);
        $this->criarOrdemServico($empresa, $pdv, $promotor, [
            'status' => StatusOrdemServico::PENDENTE,
            'horario_previsto' => now()->addHour()->format('H:i'),
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertTrue($linha['sem_sinal']);
        $this->assertSame(1, $response->json('kpis.sem_sinal'));
        $this->assertNotNull(collect($response->json('fila_acoes'))->firstWhere('tipo', 'SINAL'));
    }

    public function test_promotor_encerrado_nao_conta_como_sem_sinal(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id,
            'ultima_localizacao_em' => null,
        ]);
        $this->criarOrdemServico($empresa, $pdv, $promotor, ['status' => StatusOrdemServico::CONCLUIDA]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $linha = collect($response->json('equipe'))->firstWhere('usuario.id', $promotor->uuid);

        $this->assertFalse($linha['sem_sinal']);
        $this->assertSame(0, $response->json('kpis.sem_sinal'));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresaA->id]);
        $this->criarOrdemServico($empresaA, $pdvA, $promotorA, ['status' => StatusOrdemServico::PENDENTE]);

        $adminB = Usuario::factory()->admin()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($adminB);

        $response = $this->getJson('/api/operacao-do-dia')->assertOk();
        $this->assertSame([], $response->json('equipe'));
        $this->assertSame(0, $response->json('kpis.em_campo.total'));
    }
}
