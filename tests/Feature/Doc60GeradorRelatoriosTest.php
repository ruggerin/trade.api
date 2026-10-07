<?php

namespace Tests\Feature;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\RelatorioPersonalizado;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Fuso;
use App\Support\RelatoriosPadrao;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/60-GERADOR-DE-RELATORIOS.md — catálogo fechado (segurança), executor, CRUD, seed dos
 * padrão e paridade dos padrão com as telas fixas do doc 59.
 */
class Doc60GeradorRelatoriosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $ana;

    private Usuario $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta-feira, 23/09/2026, 12:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create();
        $this->ana = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        $this->bruno = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Bruno']);
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tz(): string
    {
        return Fuso::daEmpresa($this->empresa);
    }

    private function os(array $atributos = []): OrdemServico
    {
        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $this->empresa->id])->id,
            'usuario_id' => $this->ana->id,
            'prazo_inicio' => now()->startOfDay(),
            'prazo_fim' => now()->endOfDay()->subHour(),
            ...$atributos,
        ]);
    }

    private function definicao(array $extra = []): array
    {
        $hoje = now($this->tz())->toDateString();

        return array_replace([
            'entidade' => 'ordem_servico',
            'periodo' => ['campo' => 'prazo_fim', 'inicio' => $hoje, 'fim' => $hoje],
            'metricas' => [['chave' => 'planejadas'], ['chave' => 'executadas']],
        ], $extra);
    }

    private function executar(array $definicao)
    {
        return $this->postJson('/api/relatorios-personalizados/executar', ['definicao' => $definicao]);
    }

    private function padrao(string $chave): RelatorioPersonalizado
    {
        return RelatorioPersonalizado::where('chave', $chave)->firstOrFail();
    }

    // ——— Catálogo e segurança (§3.3) ———

    public function test_catalogo_lista_entidades_campos_e_metricas(): void
    {
        $this->getJson('/api/relatorios-personalizados/catalogo')
            ->assertOk()->assertJsonCount(3, 'data');

        $catalogo = $this->getJson('/api/relatorios-personalizados/catalogo?entidade=ordem_servico')->assertOk()->json('data');
        $this->assertSame('prazo_fim', $catalogo['campo_periodo_padrao']);
        $this->assertContains('cumprimento_ajustado', array_column($catalogo['metricas'], 'chave'));
        $promotor = collect($catalogo['campos'])->firstWhere('chave', 'promotor');
        $this->assertSame('usuarios', $promotor['fonte']);
    }

    public function test_campo_operador_e_metrica_fora_do_catalogo_sao_recusados(): void
    {
        $this->executar($this->definicao(['filtros' => ['regras' => [['campo' => 'senha_hash', 'operador' => 'em', 'valor' => ['x']]]]]))
            ->assertStatus(422)->assertJsonValidationErrors('filtros.regras.0.campo');

        $this->executar($this->definicao(['filtros' => ['regras' => [['campo' => 'status', 'operador' => 'igual', 'valor' => 'CONCLUIDA']]]]))
            ->assertStatus(422);

        $this->executar($this->definicao(['filtros' => ['regras' => [['campo' => 'status', 'operador' => 'em', 'valor' => ["CONCLUIDA'; DROP TABLE usuarios; --"]]]]]))
            ->assertStatus(422);

        $this->executar($this->definicao(['metricas' => [['chave' => 'count(*)']]]))
            ->assertStatus(422)->assertJsonValidationErrors('metricas.0.chave');

        $this->executar($this->definicao(['entidade' => 'usuarios']))
            ->assertStatus(422)->assertJsonValidationErrors('entidade');
    }

    public function test_limites_de_agrupamento_e_periodo(): void
    {
        $this->executar($this->definicao(['agrupar' => [
            ['campo' => 'status'], ['campo' => 'origem'], ['campo' => 'promotor'], ['campo' => 'loja'],
        ]]))->assertStatus(422)->assertJsonValidationErrors('agrupar');

        // Até um ano inteiro (366 dias) passa; mais que isso, não.
        $this->executar($this->definicao(['periodo' => ['inicio' => '2025-09-23', 'fim' => '2026-09-23']]))->assertOk();
        $this->executar($this->definicao(['periodo' => ['inicio' => '2025-01-01', 'fim' => '2026-09-23']]))
            ->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_so_admin_e_gestor_executam(): void
    {
        Sanctum::actingAs($this->ana);

        $this->executar($this->definicao())->assertForbidden();
        $this->getJson('/api/relatorios-personalizados')->assertForbidden();
    }

    // ——— Executor ———

    public function test_isola_a_empresa_mesmo_com_uuid_de_outra(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $outra = Empresa::factory()->create();
        $estranho = Usuario::factory()->promotor()->create(['empresa_id' => $outra->id]);
        OrdemServico::factory()->create([
            'empresa_id' => $outra->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $outra->id])->id,
            'usuario_id' => $estranho->id,
            'prazo_inicio' => now()->startOfDay(),
            'prazo_fim' => now()->endOfDay()->subHour(),
            'status' => StatusOrdemServico::CONCLUIDA,
        ]);

        $this->executar($this->definicao())->assertOk()->assertJsonPath('totais.planejadas', 1);
        $this->executar($this->definicao(['filtros' => ['regras' => [['campo' => 'promotor', 'operador' => 'em', 'valor' => [$estranho->uuid]]]]]))
            ->assertOk()->assertJsonPath('totais.planejadas', 0);
    }

    public function test_filtra_agrupa_e_combina_com_ou(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $this->os(['status' => StatusOrdemServico::PENDENTE, 'usuario_id' => $this->bruno->id]);
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'usuario_id' => $this->bruno->id]);

        $resposta = $this->executar($this->definicao([
            'agrupar' => [['campo' => 'promotor']],
            'metricas' => [['chave' => 'planejadas'], ['chave' => 'executadas'], ['chave' => 'canceladas'], ['chave' => 'cumprimento']],
        ]))->assertOk();

        $porPromotor = collect($resposta->json('linhas'))->keyBy(fn ($l) => $l['dimensoes']['promotor']['rotulo']);
        $this->assertSame(['planejadas' => 1, 'executadas' => 1, 'canceladas' => 0, 'cumprimento' => 100], $porPromotor['Ana']['valores']);
        $this->assertSame(['planejadas' => 1, 'executadas' => 0, 'canceladas' => 1, 'cumprimento' => 0], $porPromotor['Bruno']['valores']);
        $this->assertSame(50, $resposta->json('totais.cumprimento'));
        $this->assertSame(['promotor', 'planejadas', 'executadas', 'canceladas', 'cumprimento'], array_column($resposta->json('colunas'), 'chave'));

        $this->executar($this->definicao([
            'filtros' => ['combinador' => 'OU', 'regras' => [
                ['campo' => 'status', 'operador' => 'em', 'valor' => ['CONCLUIDA']],
                ['campo' => 'status', 'operador' => 'em', 'valor' => ['CANCELADA']],
            ]],
            'metricas' => [['chave' => 'executadas'], ['chave' => 'canceladas'], ['chave' => 'planejadas']],
        ]))->assertOk()->assertJsonPath('totais', ['executadas' => 1, 'canceladas' => 1, 'planejadas' => 1]);
    }

    public function test_preset_e_agrupamento_por_mes_no_fuso_da_empresa(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_fim' => Carbon::parse('2026-09-05 15:00', 'UTC')]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_fim' => Carbon::parse('2026-09-20 15:00', 'UTC')]);

        $resposta = $this->executar([
            'entidade' => 'ordem_servico',
            'periodo' => ['preset' => 'mes_atual'],
            'agrupar' => [['campo' => 'prazo_fim', 'granularidade' => 'mes']],
            'metricas' => [['chave' => 'executadas']],
        ])->assertOk();

        $resposta->assertJsonPath('periodo.data_inicio', '2026-09-01')
            ->assertJsonPath('linhas.0.dimensoes.prazo_fim.rotulo', 'set/2026')
            ->assertJsonPath('colunas.0.rotulo', 'Prazo da visita · Mês')
            ->assertJsonPath('linhas.0.valores.executadas', 2);
    }

    public function test_comparativo_traz_os_totais_do_periodo_anterior(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $this->os(['status' => StatusOrdemServico::PENDENTE, 'prazo_inicio' => now()->subDay()->startOfDay(), 'prazo_fim' => now()->subDay()->endOfDay()->subHour()]);

        $this->executar($this->definicao(['comparar' => 'anterior']))
            ->assertOk()
            ->assertJsonPath('totais.planejadas', 1)
            ->assertJsonPath('comparativo.periodo.data_inicio', now($this->tz())->subDay()->toDateString())
            ->assertJsonPath('comparativo.totais.planejadas', 1)
            ->assertJsonPath('comparativo.totais.executadas', 0);
    }

    // ——— CRUD e permissões ———

    public function test_crud_do_relatorio_do_cliente(): void
    {
        $id = $this->postJson('/api/relatorios-personalizados', ['nome' => 'Meu relatório', 'definicao' => $this->definicao()])
            ->assertCreated()
            ->assertJsonPath('data.pode_editar', true)
            ->assertJsonPath('data.definicao.filtros.combinador', 'E')
            ->json('data.id');

        $this->putJson("/api/relatorios-personalizados/{$id}", ['nome' => 'Renomeado', 'compartilhado' => true, 'definicao' => $this->definicao()])
            ->assertOk()->assertJsonPath('data.nome', 'Renomeado')->assertJsonPath('data.compartilhado', true);

        $this->getJson("/api/relatorios-personalizados/{$id}/executar")->assertOk()->assertJsonPath('totais.planejadas', 0);
        $this->deleteJson("/api/relatorios-personalizados/{$id}")->assertNoContent();
    }

    public function test_padrao_nao_se_edita_mas_se_duplica(): void
    {
        $padrao = $this->padrao('tempo_no_pdv');

        $this->putJson("/api/relatorios-personalizados/{$padrao->uuid}", ['nome' => 'X', 'definicao' => $padrao->definicao])->assertForbidden();
        $this->deleteJson("/api/relatorios-personalizados/{$padrao->uuid}")->assertForbidden();

        $this->postJson("/api/relatorios-personalizados/{$padrao->uuid}/duplicar")
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Cópia de Tempo dentro do PDV')
            ->assertJsonPath('data.padrao', false)
            ->assertJsonPath('data.pode_editar', true);
    }

    public function test_privado_so_aparece_para_quem_criou(): void
    {
        $gestor = Usuario::factory()->gestor()->comPerfil(['tela.relatorios'])->create(['empresa_id' => $this->empresa->id]);
        $privado = RelatorioPersonalizado::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id, 'nome' => 'Privado',
            'entidade' => 'ordem_servico', 'definicao' => $this->definicao(), 'compartilhado' => false,
        ]);

        Sanctum::actingAs($gestor);
        $nomes = collect($this->getJson('/api/relatorios-personalizados')->assertOk()->json('data'))->pluck('nome');
        $this->assertNotContains('Privado', $nomes);
        $this->assertContains('Tempo dentro do PDV', $nomes);
        $this->getJson("/api/relatorios-personalizados/{$privado->uuid}/executar")->assertNotFound();
    }

    public function test_gestor_sem_permissao_nao_cria_mas_executa(): void
    {
        $gestor = Usuario::factory()->gestor()->comPerfil(['tela.relatorios'])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/relatorios-personalizados', ['nome' => 'X', 'definicao' => $this->definicao()])->assertForbidden();
        $this->getJson('/api/relatorios-personalizados/'.$this->padrao('tempo_no_pdv')->uuid.'/executar')->assertOk();
    }

    public function test_gestor_com_permissao_cria(): void
    {
        $perfil = Perfil::create(['empresa_id' => $this->empresa->id, 'nome' => 'Analista', 'permissoes' => ['tela.relatorios', 'relatorios.personalizados.gerenciar'], 'ativo' => true]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/relatorios-personalizados', ['nome' => 'X', 'definicao' => $this->definicao()])->assertCreated();
    }

    public function test_gestor_sem_a_tela_de_relatorios_nao_ve_nem_executa(): void
    {
        // docs/64 — sem tela.relatorios, o gerador e os relatórios fixos são recusados.
        Sanctum::actingAs(Usuario::factory()->gestor()->comPerfil([])->create(['empresa_id' => $this->empresa->id]));

        $this->getJson('/api/relatorios-personalizados')->assertForbidden();
        $this->getJson('/api/relatorios-personalizados/'.$this->padrao('tempo_no_pdv')->uuid.'/executar')->assertForbidden();
        $this->getJson('/api/relatorios/tempo-na-loja')->assertForbidden();
    }

    public function test_planejadas_x_executadas_tambem_atende_quem_planeja_visitas(): void
    {
        // O Planejador de visitas lê esta rota — ordens_servico.gerenciar basta.
        Sanctum::actingAs(Usuario::factory()->gestor()->comPerfil(['tela.ordens_servico', 'ordens_servico.gerenciar'])->create(['empresa_id' => $this->empresa->id]));

        $this->getJson('/api/relatorios/visitas-planejadas-x-executadas')->assertOk();
        $this->getJson('/api/relatorios/tempo-na-loja')->assertForbidden();
    }

    // ——— Seed dos padrão (§6.2) ———

    public function test_toda_empresa_nasce_com_os_padrao_e_o_seed_e_idempotente(): void
    {
        $this->assertSame(
            array_keys(RelatoriosPadrao::CATALOGO),
            RelatorioPersonalizado::where('padrao', true)->orderBy('id')->pluck('chave')->all(),
        );

        $this->assertSame(['criados' => [], 'atualizados' => []], RelatoriosPadrao::completar($this->empresa));
        $this->assertSame(count(RelatoriosPadrao::CATALOGO), RelatorioPersonalizado::where('padrao', true)->count());
    }

    public function test_seed_atualiza_padrao_de_versao_antiga_e_nao_toca_no_do_cliente(): void
    {
        $padrao = $this->padrao('tempo_no_pdv');
        $padrao->update(['versao' => 0, 'nome' => 'Nome antigo']);
        $doCliente = RelatorioPersonalizado::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id, 'nome' => 'Do cliente',
            'entidade' => 'ordem_servico', 'definicao' => $this->definicao(),
        ]);

        $this->assertSame(['criados' => [], 'atualizados' => ['tempo_no_pdv']], RelatoriosPadrao::completar($this->empresa));
        $this->assertSame('Tempo dentro do PDV', $padrao->fresh()->nome);
        $this->assertSame('Do cliente', $doCliente->fresh()->nome);
    }

    public function test_comando_completa_empresas_existentes(): void
    {
        RelatorioPersonalizado::where('padrao', true)->delete();

        $this->artisan('relatorios:completar', ['--simular' => true])->assertSuccessful();
        $this->assertSame(0, RelatorioPersonalizado::where('padrao', true)->count());

        $this->artisan('relatorios:completar')->assertSuccessful();
        $this->assertSame(count(RelatoriosPadrao::CATALOGO), RelatorioPersonalizado::where('padrao', true)->count());
    }

    public function test_signup_publico_ja_cria_os_padrao(): void
    {
        $resposta = $this->postJson('/api/empresas/signup', [
            'razao_social' => 'Nova Ltda', 'nome_fantasia' => 'Nova', 'cnpj' => '11222333000144',
            'admin_nome' => 'Dona', 'admin_email' => 'dona@nova.com', 'admin_senha' => 'senha12345',
        ])->assertCreated();

        $empresa = Empresa::where('uuid', $resposta->json('empresa.id'))->firstOrFail();
        $this->assertSame(count(RelatoriosPadrao::CATALOGO), RelatorioPersonalizado::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('padrao', true)->count());
    }

    // ——— Paridade com as telas fixas do doc 59 (§6.1, critério de aceite) ———

    public function test_padrao_planejadas_bate_com_a_tela_fixa(): void
    {
        $motivo = MotivoNaoExecucao::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Promotor faltou']);
        $ontem = ['prazo_inicio' => now()->subDay()->startOfDay(), 'prazo_fim' => now()->subDay()->endOfDay()->subHour()];
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $this->os(['status' => StatusOrdemServico::EM_ANDAMENTO, 'usuario_id' => $this->bruno->id]);
        $this->os(['status' => StatusOrdemServico::PENDENTE]);
        $this->os(['status' => StatusOrdemServico::PENDENTE, 'prazo_fim' => now()->subHours(3)]);
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'responsavel_nao_execucao' => 'PROMOTOR', 'motivo_cancelamento_id' => $motivo->id]);
        $this->os(['status' => StatusOrdemServico::CANCELADA, 'responsavel_nao_execucao' => 'LOJA', 'motivo_cancelamento_texto' => 'Loja fechada', 'usuario_id' => $this->bruno->id]);
        $this->os(['status' => StatusOrdemServico::AGUARDANDO_APROVACAO]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'usuario_id' => $this->bruno->id] + $ontem);
        $this->os(['status' => StatusOrdemServico::PENDENTE] + $ontem);

        $inicio = now($this->tz())->subDays(6)->toDateString();
        $fim = now($this->tz())->toDateString();
        $fixa = $this->getJson("/api/relatorios/visitas-planejadas-x-executadas?data_inicio={$inicio}&data_fim={$fim}&tz={$this->tz()}&comparar=anterior")->assertOk();
        $gerador = $this->getJson('/api/relatorios-personalizados/'.$this->padrao('visitas_planejadas_canceladas_executadas')->uuid.'/executar')->assertOk();

        $mapa = [
            'planejadas' => 'planejadas', 'cumpridas' => 'executadas', 'em_andamento' => 'em_andamento',
            'atrasadas' => 'atrasadas', 'a_vencer' => 'a_vencer', 'canceladas' => 'canceladas',
            'canceladas_promotor' => 'canceladas_promotor', 'percentual_cumprimento' => 'cumprimento',
            'percentual_cumprimento_ajustado' => 'cumprimento_ajustado',
        ];
        foreach ($mapa as $daFixa => $doGerador) {
            $this->assertSame($fixa->json("total.$daFixa"), $gerador->json("totais.$doGerador"), "total.$daFixa");
            $this->assertSame($fixa->json("comparativo.total.$daFixa"), $gerador->json("comparativo.totais.$doGerador"), "comparativo.$daFixa");
        }
        $this->assertEquals(
            $fixa->json('total.canceladas_por_responsavel'),
            collect($gerador->json('totais.canceladas_por_responsavel'))->pluck('quantidade', 'chave')->all(),
        );
        $this->assertEquals(
            collect($fixa->json('total.canceladas_por_motivo'))->pluck('quantidade', 'motivo')->sortKeys()->all(),
            collect($gerador->json('totais.canceladas_por_motivo'))->pluck('quantidade', 'rotulo')->sortKeys()->all(),
        );

        // Linha a linha (dia × promotor), sem as visitas espontâneas, que não são ordem de serviço.
        $linhasFixa = collect($fixa->json('linhas'))->filter(fn ($l) => $l['planejadas'] + $l['canceladas'] > 0)
            ->mapWithKeys(fn ($l) => [$l['data'].'|'.($l['promotor']['id'] ?? '-') => [$l['planejadas'], $l['cumpridas'], $l['canceladas'], $l['percentual_cumprimento']]])
            ->sortKeys()->all();
        $linhasGerador = collect($gerador->json('linhas'))
            ->mapWithKeys(fn ($l) => [$l['dimensoes']['prazo_fim']['chave'].'|'.$l['dimensoes']['promotor']['chave'] => [
                $l['valores']['planejadas'], $l['valores']['executadas'], $l['valores']['canceladas'], $l['valores']['cumprimento'],
            ]])->sortKeys()->all();
        $this->assertSame($linhasFixa, $linhasGerador);
    }

    public function test_padrao_tempo_no_pdv_bate_com_a_tela_fixa(): void
    {
        $lojaA = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja A']);
        $lojaB = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja B']);
        $visita = fn (PontoVenda $loja, int $diasAtras, int $duracao, array $extra = []) => Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->ana->id, 'ponto_venda_id' => $loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->subDays($diasAtras)->setTime(13, 0),
            'fim_data' => now()->subDays($diasAtras)->setTime(13, 0)->addMinutes($duracao),
            ...$extra,
        ]);
        $visita($lojaA, 0, 40, ['afastamento_minutos' => 10]);
        $visita($lojaA, 1, 25);
        $visita($lojaA, 2, 1);
        $visita($lojaB, 0, 90);
        $visita($lojaB, 3, 800);
        $visita($lojaB, 1, 30, ['status' => StatusVisita::CANCELADA]);
        $visita($lojaB, 9, 60);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->bruno->id, 'ponto_venda_id' => $lojaA->id,
            'status' => StatusVisita::ABERTA, 'inicio_data' => now()->subHour(), 'fim_data' => null,
        ]);

        $inicio = now($this->tz())->subDays(6)->toDateString();
        $fim = now($this->tz())->toDateString();
        $fixa = $this->getJson("/api/relatorios/tempo-na-loja?data_inicio={$inicio}&data_fim={$fim}&tz={$this->tz()}&agrupar=loja&comparar=anterior")->assertOk();
        $gerador = $this->getJson('/api/relatorios-personalizados/'.$this->padrao('tempo_no_pdv')->uuid.'/executar')->assertOk();

        $mapa = ['visitas' => 'visitas', 'tempo_total_minutos' => 'tempo_total', 'media_minutos' => 'tempo_medio', 'mediana_minutos' => 'tempo_mediano', 'desconsideradas' => 'desconsideradas'];
        foreach ($mapa as $daFixa => $doGerador) {
            $this->assertSame($fixa->json("total.$daFixa"), $gerador->json("totais.$doGerador"), "total.$daFixa");
            $this->assertSame($fixa->json("comparativo.total.$daFixa"), $gerador->json("comparativo.totais.$doGerador"), "comparativo.$daFixa");
        }

        $linhasFixa = collect($fixa->json('linhas'))->map(fn ($l) => [$l['chave'], $l['visitas'], $l['tempo_total_minutos'], $l['media_minutos'], $l['mediana_minutos']])->all();
        $linhasGerador = collect($gerador->json('linhas'))
            ->filter(fn ($l) => $l['valores']['visitas'] > 0)
            ->map(fn ($l) => [$l['dimensoes']['loja']['chave'], $l['valores']['visitas'], $l['valores']['tempo_total'], $l['valores']['tempo_medio'], $l['valores']['tempo_mediano']])
            ->values()->all();
        $this->assertSame($linhasFixa, $linhasGerador);
    }

    // ——— Matriz (pivot) e opções de filtro — editor ———

    public function test_matriz_traz_subtotais_calculados_sobre_os_itens(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $this->os(['status' => StatusOrdemServico::PENDENTE]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'usuario_id' => $this->bruno->id]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'usuario_id' => $this->bruno->id, 'origem' => 'AGENDA']);

        $resposta = $this->executar($this->definicao([
            'agrupar' => [['campo' => 'promotor', 'eixo' => 'linha'], ['campo' => 'origem', 'eixo' => 'coluna']],
            'metricas' => [['chave' => 'cumprimento']],
            'visual' => 'tabela',
        ]))->assertOk();

        $porPromotor = collect($resposta->json('subtotais.linhas'))->keyBy(fn ($l) => $l['dimensoes']['promotor']['rotulo']);
        // Percentual do total da linha é sobre os itens, não a soma das células.
        $this->assertSame(50, $porPromotor['Ana']['valores']['cumprimento']);
        $this->assertSame(100, $porPromotor['Bruno']['valores']['cumprimento']);
        $this->assertCount(2, $resposta->json('subtotais.colunas'));
        $this->assertSame(75, $resposta->json('totais.cumprimento'));
        $this->assertSame('coluna', $resposta->json('definicao_resolvida.agrupar.1.eixo'));
    }

    public function test_matriz_exige_um_campo_nas_linhas_e_eixo_valido(): void
    {
        $this->executar($this->definicao(['agrupar' => [['campo' => 'promotor', 'eixo' => 'coluna']]]))
            ->assertStatus(422)->assertJsonValidationErrors('definicao.agrupar');
        $this->executar($this->definicao(['agrupar' => [['campo' => 'promotor', 'eixo' => 'diagonal']]]))
            ->assertStatus(422)->assertJsonValidationErrors('agrupar.0.eixo');
        $this->executar($this->definicao(['visual' => '3d']))
            ->assertStatus(422)->assertJsonValidationErrors('visual');
    }

    public function test_opcoes_de_filtro_buscam_so_na_empresa(): void
    {
        $outra = Empresa::factory()->create();
        Usuario::factory()->promotor()->create(['empresa_id' => $outra->id, 'nome' => 'Anabela']);

        $nomes = collect($this->getJson('/api/relatorios-personalizados/opcoes?fonte=usuarios&busca=an')->assertOk()->json('data'))->pluck('rotulo');
        $this->assertContains('Ana', $nomes);
        $this->assertNotContains('Anabela', $nomes);

        $this->getJson('/api/relatorios-personalizados/opcoes?fonte=usuarios&valores[]='.$this->bruno->uuid)
            ->assertOk()->assertJsonPath('data', [['valor' => $this->bruno->uuid, 'rotulo' => 'Bruno']]);

        $this->getJson('/api/relatorios-personalizados/opcoes?fonte=senhas')->assertStatus(422);
    }

    // ——— Registros e formulários (Fase 7) ———

    private function formulario(string $descricao, bool $alerta = false, ?Empresa $empresa = null): TipoRegistro
    {
        return TipoRegistro::create(['empresa_id' => ($empresa ?? $this->empresa)->id, 'descricao' => $descricao, 'eh_alerta' => $alerta]);
    }

    private function pergunta(TipoRegistro $tipo, string $chave, string $tipoCampo, array $extra = []): CampoTipoRegistro
    {
        return CampoTipoRegistro::create(['tipo_registro_id' => $tipo->id, 'chave' => $chave, 'rotulo' => ucfirst($chave), 'tipo_campo' => $tipoCampo, 'ordem' => 0, ...$extra]);
    }

    private function registro(TipoRegistro $tipo, array $valores = [], array $extra = [], ?PontoVenda $loja = null, ?Empresa $empresa = null): VisitaRegistro
    {
        $empresa ??= $this->empresa;
        $visita = Visita::factory()->create([
            'empresa_id' => $empresa->id,
            'usuario_id' => $empresa->is($this->empresa) ? $this->ana->id : Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
            'ponto_venda_id' => ($loja ?? PontoVenda::factory()->create(['empresa_id' => $empresa->id]))->id,
            'inicio_data' => now()->subHour(),
        ]);

        return VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, 'valores_campos' => $valores, ...$extra]);
    }

    private function definicaoRegistro(array $extra = []): array
    {
        $hoje = now($this->tz())->toDateString();

        return array_replace([
            'entidade' => 'registro',
            'periodo' => ['inicio' => $hoje, 'fim' => $hoje],
            'metricas' => [['chave' => 'registros']],
        ], $extra);
    }

    public function test_registros_contam_rupturas_e_alertas_por_loja_sem_cancelados_nem_outra_empresa(): void
    {
        $lojaA = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja A']);
        $auditoria = $this->formulario('Auditoria');
        $alerta = $this->formulario('Preço errado', alerta: true);
        $this->registro($auditoria, [], ['ruptura' => true], $lojaA);
        $this->registro($auditoria, [], ['ruptura' => false], $lojaA);
        $this->registro($alerta, [], ['alerta_resolvido_em' => now()], $lojaA);
        $this->registro($alerta, [], [], $lojaA);
        $this->registro($auditoria, [], ['ruptura' => true, 'cancelado_em' => now()], $lojaA);
        $outra = Empresa::factory()->create();
        $this->registro($this->formulario('Deles', empresa: $outra), [], ['ruptura' => true], empresa: $outra);

        $resposta = $this->executar($this->definicaoRegistro([
            'agrupar' => [['campo' => 'loja']],
            'metricas' => [['chave' => 'registros'], ['chave' => 'rupturas'], ['chave' => 'percentual_ruptura'], ['chave' => 'alertas'], ['chave' => 'alertas_abertos'], ['chave' => 'alertas_resolvidos']],
        ]))->assertOk();

        $resposta->assertJsonPath('totais', [
            'registros' => 4, 'rupturas' => 1, 'percentual_ruptura' => 25, 'alertas' => 2, 'alertas_abertos' => 1, 'alertas_resolvidos' => 1,
        ])->assertJsonPath('linhas.0.dimensoes.loja.rotulo', 'Loja A');

        $this->executar($this->definicaoRegistro(['filtros' => ['regras' => [['campo' => 'situacao_alerta', 'operador' => 'em', 'valor' => ['aberto']]]]]))
            ->assertOk()->assertJsonPath('totais.registros', 1);
    }

    public function test_perguntas_do_formulario_viram_campos_e_metricas(): void
    {
        $form = $this->formulario('Loja perfeita');
        $this->pergunta($form, 'exposto', 'BOOLEANO');
        $this->pergunta($form, 'posicao', 'MULTIPLA_ESCOLHA', ['opcoes' => ['Ponta', 'Meio']]);
        $this->pergunta($form, 'preco', 'MOEDA');
        $this->registro($form, ['exposto' => '1', 'posicao' => 'Ponta', 'preco' => '10,50']);
        $this->registro($form, ['exposto' => '1', 'posicao' => 'Ponta', 'preco' => '20']);
        $this->registro($form, ['exposto' => '0', 'posicao' => 'Meio', 'preco' => '5']);
        $this->registro($this->formulario('Outro'), ['exposto' => '1']);

        $catalogo = $this->getJson("/api/relatorios-personalizados/catalogo?entidade=registro&formulario={$form->uuid}")->assertOk()->json('data');
        $this->assertContains('resposta:posicao', array_column($catalogo['campos'], 'chave'));
        $media = collect($catalogo['metricas'])->firstWhere('chave', 'media:preco');
        $this->assertSame(['preco', 'media'], [$media['campo'], $media['agregacao']]);
        $this->assertSame('resposta:exposto', collect($catalogo['metricas'])->firstWhere('chave', 'sim:exposto')['campo']);
        $this->assertNotContains('formulario', array_column($catalogo['campos'], 'chave'));

        $resposta = $this->executar($this->definicaoRegistro([
            'formulario' => $form->uuid,
            'agrupar' => [['campo' => 'resposta:posicao']],
            'metricas' => [['chave' => 'registros'], ['chave' => 'sim:exposto'], ['chave' => 'media:preco'], ['chave' => 'soma:preco']],
        ]))->assertOk();

        $resposta->assertJsonPath('totais', ['registros' => 3, 'sim:exposto' => 67, 'media:preco' => 11.83, 'soma:preco' => 35.5]);
        $porPosicao = collect($resposta->json('linhas'))->keyBy(fn ($l) => $l['dimensoes']['resposta:posicao']['rotulo']);
        $this->assertEquals(['registros' => 2, 'sim:exposto' => 100, 'media:preco' => 15.25, 'soma:preco' => 30.5], $porPosicao['Ponta']['valores']);

        $this->executar($this->definicaoRegistro([
            'formulario' => $form->uuid,
            'filtros' => ['regras' => [['campo' => 'resposta:exposto', 'operador' => 'igual', 'valor' => false]]],
        ]))->assertOk()->assertJsonPath('totais.registros', 1);

        $this->executar($this->definicaoRegistro([
            'formulario' => $form->uuid,
            'filtros' => ['regras' => [['campo' => 'resposta:posicao', 'operador' => 'em', 'valor' => ['Lateral']]]],
        ]))->assertStatus(422);
    }

    public function test_formulario_de_outra_empresa_e_recusado(): void
    {
        $deles = $this->formulario('Deles', empresa: Empresa::factory()->create());

        $this->executar($this->definicaoRegistro(['formulario' => $deles->uuid]))
            ->assertStatus(422)->assertJsonValidationErrors('definicao.formulario');
        $this->getJson("/api/relatorios-personalizados/catalogo?entidade=registro&formulario={$deles->uuid}")->assertStatus(422);
    }

    public function test_padrao_de_rupturas_executa(): void
    {
        $this->registro($this->formulario('Auditoria'), [], ['ruptura' => true]);

        $this->getJson('/api/relatorios-personalizados/'.$this->padrao('rupturas_e_alertas_por_loja')->uuid.'/executar')
            ->assertOk()->assertJsonPath('totais.rupturas', 1);
    }

    // ——— Hierarquia de datas e filtros rápidos ———

    public function test_mesma_data_em_dois_niveis_vira_linha_do_tempo_mes_por_ano(): void
    {
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_fim' => Carbon::parse('2026-03-10 15:00', 'UTC')]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_fim' => Carbon::parse('2026-09-20 15:00', 'UTC')]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'prazo_fim' => Carbon::parse('2025-09-25 15:00', 'UTC')]);

        $resposta = $this->executar([
            'entidade' => 'ordem_servico',
            'periodo' => ['inicio' => '2025-09-23', 'fim' => '2026-09-23'],
            'agrupar' => [
                ['campo' => 'prazo_fim', 'granularidade' => 'mes_do_ano', 'eixo' => 'linha'],
                ['campo' => 'prazo_fim', 'granularidade' => 'ano', 'eixo' => 'coluna'],
            ],
            'metricas' => [['chave' => 'executadas']],
        ])->assertOk();

        $resposta->assertJsonPath('definicao_resolvida.agrupar.0.chave', 'prazo_fim:mes_do_ano')
            ->assertJsonPath('definicao_resolvida.agrupar.1.chave', 'prazo_fim:ano');
        // Mês do ano em ordem de calendário; anos da esquerda pra direita.
        $this->assertSame(['Março', 'Setembro'], collect($resposta->json('subtotais.linhas'))->pluck('dimensoes.prazo_fim:mes_do_ano.rotulo')->all());
        $this->assertSame(['2025', '2026'], collect($resposta->json('subtotais.colunas'))->pluck('dimensoes.prazo_fim:ano.rotulo')->all());
        $setembro = collect($resposta->json('linhas'))->filter(fn ($l) => $l['dimensoes']['prazo_fim:mes_do_ano']['chave'] === '09')->count();
        $this->assertSame(2, $setembro);

        $this->executar($this->definicao(['agrupar' => [['campo' => 'prazo_fim', 'granularidade' => 'mes'], ['campo' => 'prazo_fim', 'granularidade' => 'mes']]]))
            ->assertStatus(422)->assertJsonValidationErrors('definicao.agrupar');
    }

    public function test_presets_de_ano_e_dia_da_semana(): void
    {
        // 23/09/2026 é quarta-feira.
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);

        $this->executar([
            'entidade' => 'ordem_servico',
            'periodo' => ['preset' => 'ano_atual'],
            'agrupar' => [['campo' => 'prazo_fim', 'granularidade' => 'dia_da_semana']],
            'metricas' => [['chave' => 'executadas']],
        ])->assertOk()
            ->assertJsonPath('periodo.data_inicio', '2026-01-01')
            ->assertJsonPath('linhas.0.dimensoes.prazo_fim.rotulo', 'Quarta');

        $this->executar($this->definicao(['periodo' => ['preset' => 'ano_anterior']]))
            ->assertOk()->assertJsonPath('periodo.data_inicio', '2025-01-01')->assertJsonPath('periodo.data_fim', '2025-12-31');
    }

    public function test_filtros_rapidos_valem_so_na_execucao_e_somam_com_os_salvos(): void
    {
        $rede = \App\Models\RedeLoja::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Rede Norte']);
        $naRede = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'rede_loja_id' => $rede->id]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA, 'ponto_venda_id' => $naRede->id]);
        $this->os(['status' => StatusOrdemServico::CONCLUIDA]);
        $id = $this->postJson('/api/relatorios-personalizados', ['nome' => 'Executadas', 'definicao' => $this->definicao()])->assertCreated()->json('data.id');

        $this->getJson("/api/relatorios-personalizados/{$id}/executar")->assertOk()->assertJsonPath('totais.executadas', 2);

        $filtros = urlencode(json_encode([['campo' => 'rede', 'operador' => 'em', 'valor' => [$rede->uuid]]]));
        $this->getJson("/api/relatorios-personalizados/{$id}/executar?filtros={$filtros}")
            ->assertOk()->assertJsonPath('totais.executadas', 1);

        $invalido = urlencode(json_encode([['campo' => 'senha', 'operador' => 'em', 'valor' => ['x']]]));
        $this->getJson("/api/relatorios-personalizados/{$id}/executar?filtros={$invalido}")->assertStatus(422);

        // O salvo não mudou.
        $this->assertArrayNotHasKey('filtros_rapidos', RelatorioPersonalizado::where('uuid', $id)->first()->definicao);
    }

    // ——— Contagem distinta e moda ———

    public function test_contagem_distinta_e_moda_de_qualquer_campo(): void
    {
        $lojaA = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja A']);
        $lojaB = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja B']);
        $visita = fn (Usuario $quem, PontoVenda $loja, int $diasAtras) => Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $quem->id, 'ponto_venda_id' => $loja->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => now()->subDays($diasAtras)->setTime(13, 0),
            'fim_data' => now()->subDays($diasAtras)->setTime(13, 30),
        ]);
        $visita($this->ana, $lojaA, 0);
        $visita($this->ana, $lojaA, 1);
        $visita($this->bruno, $lojaB, 1);

        $catalogo = $this->getJson('/api/relatorios-personalizados/catalogo?entidade=visita')->assertOk()->json('data.metricas');
        $this->assertContains('moda:promotor', array_column($catalogo, 'chave'));
        $moda = collect($catalogo)->firstWhere('chave', 'moda:promotor');
        $this->assertSame(['texto', 'promotor', 'moda', 'Promotor'], [$moda['formato'], $moda['campo'], $moda['agregacao'], $moda['campo_rotulo']]);
        // Métrica própria da entidade não é agregação de campo.
        $this->assertArrayNotHasKey('agregacao', collect($catalogo)->firstWhere('chave', 'visitas'));

        $this->executar([
            'entidade' => 'visita',
            'periodo' => ['preset' => 'ultimos_7_dias'],
            'metricas' => [
                ['chave' => 'visitas'],
                ['chave' => 'contagem_distinta:loja'],
                ['chave' => 'contagem_distinta:inicio_data'],
                ['chave' => 'moda:promotor'],
                ['chave' => 'moda:loja'],
            ],
        ])->assertOk()->assertJsonPath('totais', [
            'visitas' => 3,
            'contagem_distinta:loja' => 2,
            'contagem_distinta:inicio_data' => 2,
            'moda:promotor' => 'Ana',
            'moda:loja' => 'Loja A',
        ]);
    }

    public function test_moda_desempata_em_ordem_alfabetica(): void
    {
        $this->assertSame('Ana', \App\Relatorios\Entidade::moda(collect(['Bruno', 'Ana'])));
        $this->assertSame(10.5, \App\Relatorios\Entidade::moda(collect([10.5, 20.0, 10.5])));
        $this->assertNull(\App\Relatorios\Entidade::moda(collect()));
    }

    public function test_moda_e_distintos_das_respostas_numericas_e_de_texto(): void
    {
        $form = $this->formulario('Preços');
        $this->pergunta($form, 'preco', 'MOEDA');
        $this->pergunta($form, 'obs', 'TEXTO');
        $this->registro($form, ['preco' => '9,90', 'obs' => 'Gôndola cheia']);
        $this->registro($form, ['preco' => '9.90', 'obs' => 'Gôndola cheia']);
        $this->registro($form, ['preco' => '12', 'obs' => 'Faltou etiqueta']);

        $this->executar($this->definicaoRegistro([
            'formulario' => $form->uuid,
            'metricas' => [['chave' => 'moda:preco'], ['chave' => 'contagem_distinta:preco'], ['chave' => 'moda:obs'], ['chave' => 'contagem_distinta:obs']],
        ]))->assertOk()->assertJsonPath('totais', [
            'moda:preco' => 9.9,
            'contagem_distinta:preco' => 2,
            'moda:obs' => 'Gôndola cheia',
            'contagem_distinta:obs' => 2,
        ]);
    }
}
