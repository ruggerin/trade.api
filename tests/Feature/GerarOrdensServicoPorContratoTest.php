<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/07-ORDEM-DE-SERVICO.md §5 — geração automática de OS por Contrato (comodato/ponto extra)
 * vencendo (`php artisan ordens-servico:gerar-por-contrato`, agendado diário em routes/console.php).
 */
class GerarOrdensServicoPorContratoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_os_pendente_para_contrato_vencendo_dentro_da_janela_padrao(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::factory()->vencendoEm(10)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato')->assertSuccessful();

        $this->assertDatabaseHas('ordens_servico', [
            'contrato_id' => $contrato->id, 'ponto_venda_id' => $pdv->id,
            'origem' => 'CONTRATO', 'status' => 'PENDENTE', 'usuario_id' => null,
        ]);
    }

    public function test_nao_cria_para_contrato_fora_da_janela_de_aviso(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->vencendoEm(60)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->where('origem', 'CONTRATO')->count());
    }

    public function test_gera_aviso_mesmo_para_contrato_ja_vencido(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        // Sem limite inferior de data — atrasado é melhor que nunca, ver docblock do comando.
        $contrato = Contrato::factory()->vencendoEm(-5)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertDatabaseHas('ordens_servico', [
            'contrato_id' => $contrato->id, 'origem' => 'CONTRATO', 'status' => 'PENDENTE',
        ]);
    }

    public function test_nao_duplica_quando_ja_existe_os_pendente_para_o_contrato(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::factory()->vencendoEm(10)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);
        OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'contrato_id' => $contrato->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertSame(1, OrdemServico::withoutGlobalScopes()->where('contrato_id', $contrato->id)->count());
    }

    public function test_ignora_contrato_inativo(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->vencendoEm(10)->inativo()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertSame(0, OrdemServico::withoutGlobalScopes()->where('origem', 'CONTRATO')->count());
    }

    public function test_respeita_parametro_de_dias_de_aviso_customizado_da_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        // Padrão é 30 dias — sem o parametro customizado, um contrato vencendo em 45 dias não
        // entraria na janela.
        $contrato = Contrato::factory()->vencendoEm(45)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);
        Parametro::create([
            'empresa_id' => $empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '60', 'ativo' => true,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertDatabaseHas('ordens_servico', [
            'contrato_id' => $contrato->id, 'origem' => 'CONTRATO', 'status' => 'PENDENTE',
        ]);
    }

    public function test_direciona_ao_promotor_unico_atribuido_ao_pdv(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        $contrato = Contrato::factory()->vencendoEm(10)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertDatabaseHas('ordens_servico', [
            'contrato_id' => $contrato->id, 'usuario_id' => $promotor->id,
        ]);
    }

    public function test_fila_aberta_quando_pdv_tem_mais_de_um_promotor_atribuido(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach([
            Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
            Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id])->id,
        ]);
        $contrato = Contrato::factory()->vencendoEm(10)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $this->assertDatabaseHas('ordens_servico', [
            'contrato_id' => $contrato->id, 'usuario_id' => null,
        ]);
    }

    public function test_prazo_fim_da_os_acompanha_a_vigencia_fim_do_contrato(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::factory()->vencendoEm(10)->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id,
        ]);

        $this->artisan('ordens-servico:gerar-por-contrato');

        $os = OrdemServico::withoutGlobalScopes()->where('contrato_id', $contrato->id)->first();
        $this->assertNotNull($os);
        $this->assertTrue($os->prazo_fim->isSameDay($contrato->vigencia_fim));
    }
}
