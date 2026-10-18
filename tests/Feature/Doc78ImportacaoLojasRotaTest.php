<?php

namespace Tests\Feature;

use App\Enums\OrigemOrdemServico;
use App\Enums\RecorrenciaAgendaVisita;
use App\Enums\StatusOrdemServico;
use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Vendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/78 — UF da loja, vendedores na importação de lojas e importação da rota de visitas (§10).
 */
class Doc78ImportacaoLojasRotaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function csv(string $url, array $linhas, array $extra = [])
    {
        $arquivo = UploadedFile::fake()->createWithContent('dados.csv', implode("\r\n", $linhas));

        return $this->post($url, ['arquivo' => $arquivo, 'simular' => '0'] + $extra, ['Accept' => 'application/json']);
    }

    private function loja(string $codigo): PontoVenda
    {
        return PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => $codigo]);
    }

    private function promotor(string $email, ?string $codigo = null): Usuario
    {
        return Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'email' => $email, 'codigo_externo' => $codigo]);
    }

    private function vendedor(string $codigo): Vendedor
    {
        return Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => $codigo, 'nome' => "Vendedor {$codigo}"]);
    }

    /** Dias ativos (0 = dom … 6 = sáb) da rota do promotor na loja. */
    private function dias(PontoVenda $loja, Usuario $promotor): array
    {
        return AgendaVisita::where('ponto_venda_id', $loja->id)->where('usuario_id', $promotor->id)
            ->where('recorrencia', RecorrenciaAgendaVisita::SEMANAL)->where('ativo', true)
            ->orderBy('dia_semana')->pluck('dia_semana')->all();
    }

    // ---- UF ----

    public function test_uf_no_cadastro_normaliza_e_recusa_invalida(): void
    {
        $loja = $this->loja('L1');

        $this->putJson("/api/pontos-venda/{$loja->uuid}", ['uf' => ' am '])->assertOk()->assertJsonPath('ponto_venda.uf', 'AM');
        $this->putJson("/api/pontos-venda/{$loja->uuid}", ['uf' => 'XX'])->assertStatus(422);
        $this->getJson('/api/pontos-venda?uf=am')->assertOk()->assertJsonCount(1, 'pontos_venda');
        $this->getJson('/api/pontos-venda?uf=SP')->assertOk()->assertJsonCount(0, 'pontos_venda');
    }

    public function test_uf_e_dimensao_e_filtro_do_gerador_de_relatorios(): void
    {
        foreach (['visita', 'registro', 'ordem_servico'] as $entidade) {
            $campos = collect($this->getJson("/api/relatorios-personalizados/catalogo?entidade={$entidade}")->assertOk()->json('data.campos'));
            $this->assertSame('UF da loja', $campos->firstWhere('chave', 'uf')['rotulo'] ?? null, $entidade);
        }
    }

    public function test_layout_antigo_de_lojas_continua_igual(): void
    {
        $loja = $this->loja('L1');
        $loja->update(['uf' => 'AM']);
        $loja->vendedores()->attach($this->vendedor('374')->id);

        $this->csv('/api/pontos-venda/importar', ['codigo_externo;fantasia', 'L1;Nome novo'])
            ->assertOk()->assertJsonPath('aplicado', true);

        $loja->refresh();
        $this->assertSame('Nome novo', $loja->fantasia);
        $this->assertSame('AM', $loja->uf);
        $this->assertSame(1, $loja->vendedores()->count());
    }

    public function test_importacao_de_lojas_com_uf_e_vendedores(): void
    {
        $l1 = $this->loja('L1');
        $l2 = $this->loja('L2');
        $l3 = $this->loja('L3');
        $v374 = $this->vendedor('374');
        $v680 = $this->vendedor('680');
        $l3->vendedores()->attach($v680->id);

        $this->csv('/api/pontos-venda/importar', [
            'codigo_externo;uf;vendedores',
            'L1;am;374 - DIEMY',
            'L2;AM;374|680 - FABIANO',
            'L3;SP;', // vendedores vazio: carteira intacta
        ])->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('vendedores_vinculados', 3);

        $this->assertSame([$v374->id], $l1->vendedores()->pluck('vendedores.id')->all());
        $this->assertEqualsCanonicalizing([$v374->id, $v680->id], $l2->vendedores()->pluck('vendedores.id')->all());
        $this->assertSame([$v680->id], $l3->vendedores()->pluck('vendedores.id')->all());
        $this->assertSame('AM', $l1->fresh()->uf);
    }

    public function test_importacao_de_lojas_recusa_uf_e_vendedor_invalidos(): void
    {
        $this->loja('L1');

        $this->csv('/api/pontos-venda/importar', ['codigo_externo;uf;vendedores', 'L1;ZZ;999'])
            ->assertOk()->assertJsonPath('aplicado', false)
            ->assertJsonPath('erros.0.linha', 2);
        $mensagem = $this->csv('/api/pontos-venda/importar', ['codigo_externo;uf;vendedores', 'L1;ZZ;999'])->json('erros.0.mensagem');
        $this->assertStringContainsString('UF "ZZ"', $mensagem);
        $this->assertStringContainsString('Vendedor 999', $mensagem);
    }

    // ---- Rota de visitas ----

    public function test_rota_cria_os_dias_marcados_e_atribui_o_promotor_a_loja(): void
    {
        $loja = $this->loja('L1');
        $jonatas = $this->promotor('jonatas@exemplo.com');

        $this->csv('/api/agendas-visita/importar', [
            'codigo_externo_loja;promotor;seg;ter;qua;qui;sex;sab;dom;horario',
            'L1;jonatas@exemplo.com;;X;;x;sim;;;8h30',
        ])->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('criadas', 3);

        $this->assertSame([2, 4, 5], $this->dias($loja, $jonatas));
        $this->assertSame('08:30', substr((string) AgendaVisita::where('dia_semana', 2)->value('horario_previsto'), 0, 5));
        $this->assertTrue($loja->promotores()->where('usuarios.id', $jonatas->id)->exists());
    }

    public function test_reimportar_nao_duplica_e_desmarcar_desativa_so_o_dia(): void
    {
        $loja = $this->loja('L1');
        $jonatas = $this->promotor('jonatas@exemplo.com');
        $cabecalho = 'codigo_externo_loja;promotor;seg;ter;qua;qui;sex;sab';

        $this->csv('/api/agendas-visita/importar', [$cabecalho, 'L1;jonatas@exemplo.com;X;;X;;X;']);
        $this->csv('/api/agendas-visita/importar', [$cabecalho, 'L1;jonatas@exemplo.com;X;;X;;X;'])
            ->assertJsonPath('criadas', 0)->assertJsonPath('agendas_desativadas', 0);
        $this->assertSame(3, AgendaVisita::count());

        $this->csv('/api/agendas-visita/importar', [$cabecalho, 'L1;jonatas@exemplo.com;X;;;;X;'])
            ->assertJsonPath('agendas_desativadas', 1);
        $this->assertSame([1, 5], $this->dias($loja, $jonatas));
        $this->assertSame(3, AgendaVisita::count(), 'desativa, nunca apaga');

        // Linha sem nenhum dia: tira a loja da rota do promotor.
        $this->csv('/api/agendas-visita/importar', [$cabecalho, 'L1;jonatas@exemplo.com;;;;;;'])
            ->assertJsonPath('agendas_desativadas', 2);
        $this->assertSame([], $this->dias($loja, $jonatas));
    }

    public function test_rota_nao_mexe_em_outro_promotor_nem_em_visita_avulsa(): void
    {
        $loja = $this->loja('L1');
        $jonatas = $this->promotor('jonatas@exemplo.com');
        $outro = $this->promotor('outro@exemplo.com');
        AgendaVisita::create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $loja->id, 'usuario_id' => $outro->id, 'recorrencia' => RecorrenciaAgendaVisita::SEMANAL, 'dia_semana' => 3, 'prioridade' => 'MEDIA', 'obrigatoria' => true, 'ativo' => true]);
        AgendaVisita::create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $loja->id, 'usuario_id' => $jonatas->id, 'recorrencia' => RecorrenciaAgendaVisita::DATA_UNICA, 'data' => now()->addWeek()->toDateString(), 'prioridade' => 'MEDIA', 'obrigatoria' => true, 'ativo' => true]);

        $this->csv('/api/agendas-visita/importar', ['codigo_externo_loja;promotor;seg', 'L1;jonatas@exemplo.com;'])->assertOk();

        $this->assertSame([3], $this->dias($loja, $outro));
        $this->assertTrue(AgendaVisita::where('recorrencia', RecorrenciaAgendaVisita::DATA_UNICA)->where('ativo', true)->exists());
    }

    public function test_promotor_padrao_codigo_externo_dias_somados_e_aviso_sem_vendedor(): void
    {
        $l1 = $this->loja('L1');
        $l2 = $this->loja('L2');
        $l2->vendedores()->attach($this->vendedor('374')->id);
        $jonatas = $this->promotor('jonatas@exemplo.com');
        $maria = $this->promotor('maria@exemplo.com', 'P015');

        $resposta = $this->csv('/api/agendas-visita/importar', [
            'codigo_externo_loja;promotor;seg;ter;qua',
            'L1;;X;;',       // promotor padrão da tela
            'L1;;;X;',       // mesma loja + promotor: soma
            'L2;P015;;;X',   // código externo
        ], ['promotor_padrao' => 'jonatas@exemplo.com'])->assertOk()->assertJsonPath('aplicado', true);

        $this->assertSame([1, 2], $this->dias($l1, $jonatas));
        $this->assertSame([3], $this->dias($l2, $maria));
        // L1 sem vendedor: aviso nas duas linhas dela, e mesmo assim gravou.
        $this->assertCount(2, $resposta->json('avisos'));
    }

    public function test_rota_recusa_linhas_invalidas_sem_gravar_nada(): void
    {
        $this->loja('L1');
        $this->promotor('jonatas@exemplo.com');
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'email' => 'gestor@exemplo.com']);

        $resposta = $this->csv('/api/agendas-visita/importar', [
            'codigo_externo_loja;promotor;seg;horario',
            'L1;jonatas@exemplo.com;X;',        // válida
            'L404;jonatas@exemplo.com;X;',      // loja inexistente
            'L1;;X;',                           // sem promotor e sem padrão
            'L1;ninguem@exemplo.com;X;',        // promotor inexistente
            "L1;{$gestor->email};X;",           // não é promotor
            'L1;jonatas@exemplo.com;talvez;',   // flag inválida
            'L1;jonatas@exemplo.com;X;25:00',   // horário inválido
        ])->assertOk()->assertJsonPath('aplicado', false);

        $this->assertCount(6, $resposta->json('erros'));
        $this->assertSame(0, AgendaVisita::count());
    }

    public function test_rota_exige_a_permissao_da_agenda(): void
    {
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]));

        $this->csv('/api/agendas-visita/importar', ['codigo_externo_loja;promotor;seg', 'L1;x@x.com;X'])->assertForbidden();
    }

    // ---- Rota de visitas: substituir (docs/78 §4.1) ----

    private function regra(PontoVenda $loja, Usuario $promotor, int $dia): AgendaVisita
    {
        return AgendaVisita::create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $loja->id, 'usuario_id' => $promotor->id, 'recorrencia' => RecorrenciaAgendaVisita::SEMANAL, 'dia_semana' => $dia, 'prioridade' => 'MEDIA', 'obrigatoria' => true, 'ativo' => true]);
    }

    private function osDeHoje(AgendaVisita $agenda, StatusOrdemServico $status): OrdemServico
    {
        return OrdemServico::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $agenda->ponto_venda_id, 'usuario_id' => $agenda->usuario_id,
            'origem' => OrigemOrdemServico::AGENDA, 'agenda_visita_id' => $agenda->id, 'status' => $status,
            'prazo_inicio' => now()->startOfDay(), 'prazo_fim' => now()->endOfDay(),
        ]);
    }

    /**
     * Jonatas: L1 (seg, fica no arquivo), L2 e L3 (hoje, saem). Em L3 ele ainda tem uma visita
     * pontual futura. Outro promotor em L2. OS de hoje: pendente em L2, em andamento em L3.
     */
    private function cenarioSubstituir(): array
    {
        [$l1, $l2, $l3] = [$this->loja('L1'), $this->loja('L2'), $this->loja('L3')];
        $jonatas = $this->promotor('jonatas@exemplo.com');
        $outro = $this->promotor('outro@exemplo.com');
        $hoje = now()->dayOfWeek;

        $this->regra($l1, $jonatas, 1);
        $r2 = $this->regra($l2, $jonatas, $hoje);
        $r3 = $this->regra($l3, $jonatas, $hoje);
        $this->regra($l2, $outro, 3);
        AgendaVisita::create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $l3->id, 'usuario_id' => $jonatas->id, 'recorrencia' => RecorrenciaAgendaVisita::DATA_UNICA, 'data' => now()->addWeek()->toDateString(), 'prioridade' => 'MEDIA', 'obrigatoria' => true, 'ativo' => true]);
        foreach ([$l1, $l2, $l3] as $loja) {
            $loja->promotores()->attach($jonatas->id);
        }
        $l2->promotores()->attach($outro->id);

        return [
            'lojas' => [$l1, $l2, $l3], 'jonatas' => $jonatas, 'outro' => $outro,
            'pendente' => $this->osDeHoje($r2, StatusOrdemServico::PENDENTE),
            'em_andamento' => $this->osDeHoje($r3, StatusOrdemServico::EM_ANDAMENTO),
        ];
    }

    private function rotaSubstituindo(array $linhas, bool $simular = false)
    {
        $arquivo = UploadedFile::fake()->createWithContent('dados.csv', implode("\r\n", $linhas));

        return $this->post('/api/agendas-visita/importar', ['arquivo' => $arquivo, 'simular' => $simular ? '1' : '0', 'substituir' => '1'], ['Accept' => 'application/json']);
    }

    private function atendidaPor(PontoVenda $loja, Usuario $promotor): bool
    {
        return $loja->promotores()->where('usuarios.id', $promotor->id)->exists();
    }

    public function test_rota_substituir_tira_do_promotor_as_lojas_fora_do_arquivo(): void
    {
        ['lojas' => [$l1, $l2, $l3], 'jonatas' => $jonatas, 'outro' => $outro, 'pendente' => $pendente, 'em_andamento' => $emAndamento] = $this->cenarioSubstituir();
        $linhas = ['codigo_externo_loja;promotor;seg', 'L1;jonatas@exemplo.com;X'];

        // Prévia: conta tudo, não muda nada.
        $this->rotaSubstituindo($linhas, simular: true)->assertOk()
            ->assertJsonPath('aplicado', false)->assertJsonPath('agendas_desativadas', 2)
            ->assertJsonPath('lojas_saindo', 2)->assertJsonPath('os_canceladas', 1)->assertJsonPath('promotores_desatribuidos', 1);
        $this->assertSame(StatusOrdemServico::PENDENTE, $pendente->fresh()->status);
        $this->assertTrue($this->atendidaPor($l2, $jonatas));

        $this->rotaSubstituindo($linhas)->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('agendas_desativadas', 2);

        $this->assertSame([1], $this->dias($l1, $jonatas));
        $this->assertSame([], $this->dias($l2, $jonatas));
        $this->assertSame([], $this->dias($l3, $jonatas));
        $this->assertSame([3], $this->dias($l2, $outro), 'outro promotor não muda');
        $this->assertSame(5, AgendaVisita::count(), 'desativa, nunca apaga');
        $this->assertTrue(AgendaVisita::where('recorrencia', RecorrenciaAgendaVisita::DATA_UNICA)->where('ativo', true)->exists());

        // Visita de hoje não iniciada é cancelada com rastro; a já iniciada fica, com o vínculo.
        $pendente->refresh();
        $this->assertSame(StatusOrdemServico::CANCELADA, $pendente->status);
        $this->assertNotNull($pendente->cancelada_por_id);
        $this->assertSame(StatusOrdemServico::EM_ANDAMENTO, $emAndamento->fresh()->status);
        $this->assertNotNull($emAndamento->fresh()->agenda_visita_id);

        // Sai do app só onde ficou sem agenda nenhuma (L3 ainda tem a pontual futura).
        $this->assertTrue($this->atendidaPor($l1, $jonatas));
        $this->assertFalse($this->atendidaPor($l2, $jonatas));
        $this->assertTrue($this->atendidaPor($l3, $jonatas));
        $this->assertTrue($this->atendidaPor($l2, $outro));
    }

    public function test_rota_sem_substituir_nao_mexe_em_loja_fora_do_arquivo(): void
    {
        ['lojas' => [, $l2], 'jonatas' => $jonatas, 'pendente' => $pendente] = $this->cenarioSubstituir();

        $resposta = $this->csv('/api/agendas-visita/importar', ['codigo_externo_loja;promotor;seg', 'L1;jonatas@exemplo.com;X'])
            ->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('agendas_desativadas', 0);

        $this->assertArrayNotHasKey('lojas_saindo', $resposta->json());
        $this->assertSame([now()->dayOfWeek], $this->dias($l2, $jonatas));
        $this->assertSame(StatusOrdemServico::PENDENTE, $pendente->fresh()->status);
    }

    public function test_rota_substituir_com_linha_invalida_nao_desativa_nada(): void
    {
        ['lojas' => [, $l2], 'jonatas' => $jonatas] = $this->cenarioSubstituir();

        $this->rotaSubstituindo(['codigo_externo_loja;promotor;seg', 'L1;jonatas@exemplo.com;X', 'L9;jonatas@exemplo.com;X'])
            ->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('erros.0.linha', 3);

        $this->assertSame([now()->dayOfWeek], $this->dias($l2, $jonatas));
    }

    public function test_rota_substituir_desfaz_tudo_se_a_gravacao_falhar(): void
    {
        ['lojas' => [, $l2], 'jonatas' => $jonatas, 'pendente' => $pendente] = $this->cenarioSubstituir();
        // Falha no último passo (tirar o promotor da loja), depois de desativar e cancelar.
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'delete from "promotor_pontos_venda"')) {
                throw new \RuntimeException('falha simulada');
            }
        });

        $this->rotaSubstituindo(['codigo_externo_loja;promotor;seg', 'L1;jonatas@exemplo.com;X'])->assertStatus(500);

        $this->assertSame([now()->dayOfWeek], $this->dias($l2, $jonatas));
        $this->assertSame(StatusOrdemServico::PENDENTE, $pendente->fresh()->status);
        $this->assertTrue($this->atendidaPor($l2, $jonatas));
    }
}
