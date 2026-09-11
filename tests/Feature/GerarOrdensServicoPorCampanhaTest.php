<?php

namespace Tests\Feature;

use App\Models\CampanhaAuditoria;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/07-ORDEM-DE-SERVICO.md — geração automática de OS por campanha recorrente
 * (`php artisan ordens-servico:gerar-por-campanha`, agendado diário em routes/console.php).
 */
class GerarOrdensServicoPorCampanhaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_os_pendente_por_pdv_ativo_para_campanha_recorrente_vigente(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv1 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv2 = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-campanha')->assertSuccessful();

        $this->assertDatabaseHas('ordens_servico', [
            'campanha_id' => $campanha->id, 'ponto_venda_id' => $pdv1->id,
            'origem' => 'CAMPANHA', 'status' => 'PENDENTE', 'usuario_id' => null,
        ]);
        $this->assertDatabaseHas('ordens_servico', [
            'campanha_id' => $campanha->id, 'ponto_venda_id' => $pdv2->id,
            'origem' => 'CAMPANHA', 'status' => 'PENDENTE',
        ]);
    }

    public function test_nao_duplica_quando_ja_existe_os_pendente_para_o_par_campanha_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'campanha_id' => $campanha->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertSame(1, OrdemServico::withoutGlobalScopes()->where('campanha_id', $campanha->id)->count());
    }

    public function test_nao_cria_antes_de_completar_a_frequencia_desde_o_ultimo_ciclo(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        // Ciclo concluído há 5 dias — faltam 10 pra completar os 15 de frequência.
        OrdemServico::factory()->concluida()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'campanha_id' => $campanha->id,
            'prazo_fim' => now()->subDays(5),
        ]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertSame(1, OrdemServico::withoutGlobalScopes()->where('campanha_id', $campanha->id)->count());
    }

    public function test_cria_novo_ciclo_quando_a_frequencia_ja_passou_desde_o_ultimo_concluido(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->concluida()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'campanha_id' => $campanha->id,
            'prazo_fim' => now()->subDays(20),
        ]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertSame(2, OrdemServico::withoutGlobalScopes()->where('campanha_id', $campanha->id)->count());
        $this->assertDatabaseHas('ordens_servico', [
            'campanha_id' => $campanha->id, 'ponto_venda_id' => $pdv->id, 'status' => 'PENDENTE',
        ]);
    }

    public function test_ignora_campanha_nao_recorrente_sem_frequencia_ou_fora_de_vigencia(): void
    {
        $empresa = Empresa::factory()->create();
        PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]); // sem frequencia_dias
        CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id, 'execucao_recorrente' => false, 'frequencia_dias' => 15]);
        CampanhaAuditoria::factory()->recorrente(15)->expirada()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->recorrente(15)->futura()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->recorrente(15)->inativa()->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->count());
    }

    public function test_ignora_pdv_inativo(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'ativo' => false]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->where('campanha_id', $campanha->id)->count());
    }

    public function test_direciona_ao_promotor_unico_atribuido_ao_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertDatabaseHas('ordens_servico', [
            'campanha_id' => $campanha->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);
    }

    public function test_fila_aberta_quando_pdv_tem_mais_de_um_promotor_atribuido(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->recorrente(15)->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach([
            Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
            Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $this->assertDatabaseHas('ordens_servico', [
            'campanha_id' => $campanha->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => null,
        ]);
    }

    public function test_prazo_fim_e_limitado_pela_vigencia_fim_da_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $campanha = CampanhaAuditoria::factory()->create([
            'empresa_id' => $empresa->id,
            'execucao_recorrente' => true,
            'frequencia_dias' => 30,
            'vigencia_inicio' => now()->subDay(),
            'vigencia_fim' => now()->addDays(5),
        ]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        $this->artisan('ordens-servico:gerar-por-campanha');

        $os = OrdemServico::withoutGlobalScopes()->where('campanha_id', $campanha->id)->first();
        $this->assertNotNull($os);
        $this->assertTrue($os->prazo_fim->isSameDay($campanha->vigencia_fim));
    }
}
