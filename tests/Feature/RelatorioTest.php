<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relatórios agregados (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §2): visitas planejadas ×
 * executadas e respostas por pergunta de formulário.
 */
class RelatorioTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana Promotora']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function pdv(?Empresa $empresa = null): PontoVenda
    {
        return PontoVenda::factory()->create(['empresa_id' => ($empresa ?? $this->empresa)->id]);
    }

    private function os(array $atributos): OrdemServico
    {
        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => $this->pdv()->id,
            'prazo_inicio' => now()->startOfDay(),
            'prazo_fim' => now()->endOfDay()->subHour(),
            ...$atributos,
        ]);
    }

    // ---- visitas planejadas × executadas ----

    public function test_promotor_nao_acessa_relatorios(): void
    {
        Sanctum::actingAs($this->promotor);

        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas')->assertForbidden();
        $this->getJson('/api/relatorios/respostas-formulario?tipo_registro_uuid=x')->assertForbidden();
    }

    public function test_classifica_cumprida_atrasada_a_vencer_e_espontanea(): void
    {
        $hoje = now()->toDateString();

        // Cumprida: OS concluída, com a visita executada vinculada.
        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->pdv()->id, 'inicio_data' => now()->startOfDay()->addHours(8),
        ]);
        $cumprida = $this->os(['usuario_id' => $this->promotor->id, 'status' => StatusOrdemServico::CONCLUIDA, 'visita_id' => $visita->id]);
        $visita->update(['ordem_servico_id' => $cumprida->id]);

        // Atrasada: em aberto com prazo já vencido hoje.
        $this->os(['usuario_id' => $this->promotor->id, 'prazo_fim' => now()->subMinute()]);
        // A vencer: em aberto com prazo ainda hoje, mais tarde.
        $this->os(['usuario_id' => $this->promotor->id, 'prazo_fim' => now()->addMinutes(30)]);
        // Cancelada não entra no planejado.
        $this->os(['usuario_id' => $this->promotor->id, 'status' => StatusOrdemServico::CANCELADA]);
        // Espontânea: visita sem OS.
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->pdv()->id, 'inicio_data' => now()->startOfDay()->addHours(10),
        ]);

        $resposta = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}")->assertOk();

        $resposta->assertJsonPath('total.planejadas', 3)
            ->assertJsonPath('total.cumpridas', 1)
            ->assertJsonPath('total.atrasadas', 1)
            ->assertJsonPath('total.a_vencer', 1)
            ->assertJsonPath('total.espontaneas', 1)
            ->assertJsonPath('total.percentual_cumprimento', 33)
            ->assertJsonCount(1, 'linhas')
            ->assertJsonPath('linhas.0.promotor.nome', 'Ana Promotora');
    }

    public function test_filtra_por_promotor_e_ignora_outra_empresa(): void
    {
        $hoje = now()->toDateString();
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->os(['usuario_id' => $this->promotor->id]);
        $this->os(['usuario_id' => $outroPromotor->id]);

        $outraEmpresa = Empresa::factory()->create();
        OrdemServico::factory()->create([
            'empresa_id' => $outraEmpresa->id, 'ponto_venda_id' => $this->pdv($outraEmpresa)->id,
            'prazo_inicio' => now()->startOfDay(), 'prazo_fim' => now()->endOfDay()->subHour(),
        ]);

        $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}")
            ->assertOk()->assertJsonPath('total.planejadas', 2);

        $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}&usuario_uuid={$this->promotor->uuid}")
            ->assertOk()->assertJsonPath('total.planejadas', 1);
    }

    public function test_dia_fecha_no_fuso_de_quem_consulta(): void
    {
        // 02:30 UTC de 19/09 ainda é 23:30 de 18/09 em São Paulo — precisa cair no dia 18.
        $this->travelTo(now()->setDate(2026, 9, 19)->setTime(12, 0));
        $this->os(['usuario_id' => $this->promotor->id, 'prazo_fim' => now()->setDate(2026, 9, 19)->setTime(2, 30), 'prazo_inicio' => now()->subDay()]);

        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas?data_inicio=2026-09-18&data_fim=2026-09-18&tz=America/Sao_Paulo')
            ->assertOk()
            ->assertJsonPath('total.planejadas', 1)
            ->assertJsonPath('linhas.0.data', '2026-09-18');

        // Sem tz vale o fuso da aplicação (UTC): a mesma OS é do dia 19.
        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas?data_inicio=2026-09-18&data_fim=2026-09-18')
            ->assertOk()->assertJsonPath('total.planejadas', 0);
    }

    public function test_periodo_muito_longo_e_rejeitado(): void
    {
        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas?data_inicio=2020-01-01&data_fim=2026-01-01')
            ->assertUnprocessable();
    }

    // ---- respostas por pergunta ----

    private function registro(TipoRegistro $tipo, array $valores, array $extra = []): VisitaRegistro
    {
        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => $this->pdv()->id, 'inicio_data' => now()->subHour(),
        ]);

        return VisitaRegistro::create([
            'visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'valores_campos' => $valores, ...$extra,
        ]);
    }

    private function campo(TipoRegistro $tipo, string $chave, string $tipoCampo, int $ordem = 0): CampoTipoRegistro
    {
        return CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => $chave, 'rotulo' => ucfirst($chave), 'tipo_campo' => $tipoCampo, 'ordem' => $ordem,
        ]);
    }

    public function test_agrega_booleano_numero_e_ignora_cancelados(): void
    {
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Auditoria']);
        $this->campo($tipo, 'exposto', 'BOOLEANO', 0);
        $this->campo($tipo, 'preco', 'MOEDA', 1);

        $this->registro($tipo, ['exposto' => '1', 'preco' => '10,50']);
        $this->registro($tipo, ['exposto' => '1', 'preco' => '20']);
        $this->registro($tipo, ['exposto' => '0']);
        $this->registro($tipo, ['exposto' => '0'], ['cancelado_em' => now()]);

        $r = $this->getJson("/api/relatorios/respostas-formulario?tipo_registro_uuid={$tipo->uuid}")->assertOk();

        $r->assertJsonPath('total_registros', 3)
            ->assertJsonPath('campos.0.respostas', 3)
            ->assertJsonPath('campos.0.contagem.0.valor', 'Sim')
            ->assertJsonPath('campos.0.contagem.0.quantidade', 2)
            ->assertJsonPath('campos.1.estatisticas.soma', 30.5)
            ->assertJsonPath('campos.1.estatisticas.media', 15.25)
            ->assertJsonPath('campos.1.estatisticas.minimo', 10.5)
            ->assertJsonPath('campos.1.estatisticas.maximo', 20);
    }

    public function test_campo_chave_restringe_e_mix_lista_produtos_ausentes(): void
    {
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja perfeita']);
        $this->campo($tipo, 'exposto', 'BOOLEANO', 0);
        $this->campo($tipo, 'mix', 'SORTIMENTO', 1);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Amaciante X']);

        $this->registro($tipo, ['mix' => json_encode(['presentes' => [], 'ausentes' => [$produto->uuid]])]);
        $this->registro($tipo, ['mix' => json_encode(['presentes' => [$produto->uuid], 'ausentes' => []])]);

        $r = $this->getJson("/api/relatorios/respostas-formulario?tipo_registro_uuid={$tipo->uuid}&campo_chave=mix")->assertOk();

        $r->assertJsonCount(1, 'campos')
            ->assertJsonPath('campos.0.ausencias.checklists', 2)
            ->assertJsonPath('campos.0.ausencias.produtos.0.produto', 'Amaciante X')
            ->assertJsonPath('campos.0.ausencias.produtos.0.vezes_ausente', 1);
    }

    public function test_ruptura_e_filtro_por_promotor(): void
    {
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_ruptura' => true]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Sabão Y']);
        $this->registro($tipo, [], ['ruptura' => true, 'produto_auditoria_id' => $produto->id]);
        $this->registro($tipo, [], ['ruptura' => true, 'produto_auditoria_id' => $produto->id]);

        $this->getJson("/api/relatorios/respostas-formulario?tipo_registro_uuid={$tipo->uuid}")
            ->assertOk()
            ->assertJsonPath('rupturas.total', 2)
            ->assertJsonPath('rupturas.por_produto.0.produto', 'Sabão Y')
            ->assertJsonPath('rupturas.por_produto.0.quantidade', 2);

        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->getJson("/api/relatorios/respostas-formulario?tipo_registro_uuid={$tipo->uuid}&usuario_uuid={$outro->uuid}")
            ->assertOk()->assertJsonPath('total_registros', 0);
    }

    public function test_formulario_de_outra_empresa_da_404(): void
    {
        $outra = TipoRegistro::create(['empresa_id' => Empresa::factory()->create()->id, 'descricao' => 'Alheio']);

        $this->getJson("/api/relatorios/respostas-formulario?tipo_registro_uuid={$outra->uuid}")->assertNotFound();
    }

    // ---- filtro por PDV e exportação em PDF ----

    public function test_visitas_planejadas_filtra_por_ponto_de_venda(): void
    {
        $hoje = now()->toDateString();
        $alvo = $this->pdv();
        $this->os(['usuario_id' => $this->promotor->id, 'ponto_venda_id' => $alvo->id]);
        $this->os(['usuario_id' => $this->promotor->id]); // outro PDV

        $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$hoje}&data_fim={$hoje}&ponto_venda_uuid={$alvo->uuid}")
            ->assertOk()->assertJsonPath('total.planejadas', 1);
    }

    public function test_visitas_planejadas_em_pdf(): void
    {
        $hoje = now()->toDateString();
        $this->os(['usuario_id' => $this->promotor->id]);

        $resposta = $this->get("/api/relatorios/visitas-planejadas-x-executadas/pdf?data_inicio={$hoje}&data_fim={$hoje}&usuario_uuid={$this->promotor->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $resposta->getContent());
    }

    public function test_respostas_formulario_em_pdf(): void
    {
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Auditoria']);
        $this->campo($tipo, 'exposto', 'BOOLEANO');
        $this->registro($tipo, ['exposto' => '1']);

        $resposta = $this->get("/api/relatorios/respostas-formulario/pdf?tipo_registro_uuid={$tipo->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $resposta->getContent());
    }

    public function test_promotor_nao_baixa_pdf_dos_relatorios(): void
    {
        Sanctum::actingAs($this->promotor);

        $this->get('/api/relatorios/visitas-planejadas-x-executadas/pdf')->assertForbidden();
        $this->get('/api/relatorios/respostas-formulario/pdf?tipo_registro_uuid=x')->assertForbidden();
    }
}
