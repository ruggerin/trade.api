<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\RelatorioPersonalizado;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/63-ORGANIZACAO-DO-MENU-E-NOME-LOJA.md §1.7 — relatórios fixados no menu, alcance "empresa"
 * (todo mundo vê) e "meu" (só quem fixou).
 */
class Doc63RelatoriosFixadosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->gestor = Usuario::factory()->gestor()->comPerfil(['tela.relatorios'])->create(['empresa_id' => $this->empresa->id]);
    }

    private function padrao(string $chave): RelatorioPersonalizado
    {
        return RelatorioPersonalizado::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('chave', $chave)->firstOrFail();
    }

    private function relatorio(Usuario $dono, bool $compartilhado, string $nome = 'Meu relatório'): RelatorioPersonalizado
    {
        return RelatorioPersonalizado::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $dono->id, 'nome' => $nome,
            'entidade' => 'ordem_servico', 'compartilhado' => $compartilhado,
            'definicao' => ['entidade' => 'ordem_servico', 'metricas' => [['chave' => 'planejadas']]],
        ]);
    }

    private function fixar(RelatorioPersonalizado $r, string $alcance, bool $fixado = true)
    {
        return $this->putJson("/api/relatorios-personalizados/{$r->uuid}/fixar", ['alcance' => $alcance, 'fixado' => $fixado]);
    }

    private function nomesNoMenu(): array
    {
        return collect($this->getJson('/api/relatorios-personalizados/menu')->assertOk()->json('data'))->pluck('nome')->all();
    }

    public function test_fixado_da_empresa_aparece_para_todos_e_o_meu_so_para_mim(): void
    {
        Sanctum::actingAs($this->admin);
        $this->fixar($this->padrao('tempo_no_pdv'), 'empresa')->assertOk()->assertJsonPath('data.fixado_empresa', true);
        $this->fixar($this->padrao('rupturas_e_alertas_por_loja'), 'meu')->assertOk()->assertJsonPath('data.fixado_meu', true);
        $this->assertSame(['Tempo dentro do PDV', 'Rupturas e alertas por loja'], $this->nomesNoMenu());

        Sanctum::actingAs($this->gestor);
        $this->assertSame(['Tempo dentro do PDV'], $this->nomesNoMenu());
    }

    public function test_fixado_nos_dois_alcances_aparece_uma_vez(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->padrao('tempo_no_pdv');
        $this->fixar($r, 'empresa')->assertOk();
        $this->fixar($r, 'meu')->assertOk();

        $menu = $this->getJson('/api/relatorios-personalizados/menu')->assertOk()->json('data');
        $this->assertCount(1, $menu);
        $this->assertSame('empresa', $menu[0]['alcance']);
    }

    public function test_gestor_sem_permissao_so_fixa_no_proprio_menu(): void
    {
        Sanctum::actingAs($this->gestor);
        $this->fixar($this->padrao('tempo_no_pdv'), 'empresa')->assertForbidden();
        $this->fixar($this->padrao('tempo_no_pdv'), 'meu')->assertOk();
    }

    public function test_gestor_com_permissao_fixa_para_a_empresa(): void
    {
        $perfil = Perfil::create(['empresa_id' => $this->empresa->id, 'nome' => 'Analista', 'permissoes' => ['tela.relatorios', 'relatorios.personalizados.gerenciar'], 'ativo' => true]);
        $this->gestor->update(['perfil_id' => $perfil->id]);
        Sanctum::actingAs($this->gestor);

        $this->fixar($this->padrao('tempo_no_pdv'), 'empresa')->assertOk();
    }

    public function test_privado_nao_vai_para_o_menu_da_empresa(): void
    {
        Sanctum::actingAs($this->admin);
        $privado = $this->relatorio($this->admin, false);

        $this->fixar($privado, 'empresa')->assertUnprocessable();
        $this->fixar($privado, 'meu')->assertOk();
    }

    public function test_descompartilhar_tira_do_menu_dos_outros(): void
    {
        $r = $this->relatorio($this->admin, true, 'Compartilhado');
        Sanctum::actingAs($this->admin);
        $this->fixar($r, 'empresa')->assertOk();
        $this->fixar($r, 'meu')->assertOk();
        Sanctum::actingAs($this->gestor);
        $this->fixar($r, 'meu')->assertOk();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/relatorios-personalizados/{$r->uuid}", [
            'nome' => 'Compartilhado', 'compartilhado' => false, 'definicao' => $r->definicao,
        ])->assertOk();

        $this->assertFalse($r->fresh()->fixado_empresa);
        $this->assertSame(['Compartilhado'], $this->nomesNoMenu(), 'o criador continua com o "meu"');
        Sanctum::actingAs($this->gestor);
        $this->assertSame([], $this->nomesNoMenu());
    }

    public function test_excluir_relatorio_tira_do_menu(): void
    {
        $r = $this->relatorio($this->admin, true);
        Sanctum::actingAs($this->admin);
        $this->fixar($r, 'meu')->assertOk();
        $this->deleteJson("/api/relatorios-personalizados/{$r->uuid}")->assertNoContent();

        $this->assertSame([], $this->nomesNoMenu());
    }

    public function test_teto_de_fixados_do_meu_menu(): void
    {
        Sanctum::actingAs($this->admin);
        for ($i = 1; $i <= 5; $i++) {
            $this->fixar($this->relatorio($this->admin, false, "R{$i}"), 'meu')->assertOk();
        }
        $this->fixar($this->relatorio($this->admin, false, 'R6'), 'meu')->assertUnprocessable();
    }

    public function test_desafixar(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->padrao('tempo_no_pdv');
        $this->fixar($r, 'empresa')->assertOk();
        $this->fixar($r, 'empresa', false)->assertOk()->assertJsonPath('data.fixado_empresa', false);

        $this->assertSame([], $this->nomesNoMenu());
    }

    public function test_listagem_diz_o_que_esta_fixado(): void
    {
        Sanctum::actingAs($this->admin);
        $this->fixar($this->padrao('tempo_no_pdv'), 'meu')->assertOk();

        $item = collect($this->getJson('/api/relatorios-personalizados')->assertOk()->json('data'))->firstWhere('chave', 'tempo_no_pdv');
        $this->assertTrue($item['fixado_meu']);
        $this->assertFalse($item['fixado_empresa']);
    }

    public function test_promotor_nao_ve_o_menu_de_relatorios(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/relatorios-personalizados/menu')->assertForbidden();
    }
}
