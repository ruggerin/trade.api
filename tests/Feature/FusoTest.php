<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Support\Fuso;
use App\Support\Instante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fundação de fuso horário — docs/50-SUPORTE-MULTIPLOS-FUSOS-HORARIOS.md: instante sempre UTC,
 * corte de dia pela empresa, horário marcado pela loja (herda da empresa).
 */
class FusoTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_nasce_em_sao_paulo_e_loja_herda_da_empresa(): void
    {
        // Default do BANCO (a fábrica de testes fixa UTC pra manter neutros os testes antigos).
        $empresa = Empresa::factory()->create();
        DB::table('empresas')->where('id', $empresa->id)->update(['fuso' => DB::raw('DEFAULT')]);
        $this->assertSame('America/Sao_Paulo', $empresa->refresh()->fuso);

        $empresa->update(['fuso' => 'America/Manaus']);
        $loja = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $this->assertSame('America/Manaus', Fuso::daLoja($loja));

        $loja->update(['fuso' => 'America/Rio_Branco']);
        $this->assertSame('America/Rio_Branco', Fuso::daLoja($loja->refresh()));
    }

    public function test_intervalo_do_dia_em_manaus_vira_utc(): void
    {
        [$inicio, $fim] = Fuso::intervaloDoDia('2026-09-29', 'America/Manaus');

        $this->assertSame('2026-09-29 04:00:00', $inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 03:59:59', $fim->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $inicio->tzName);
    }

    public function test_horario_marcado_da_loja_vira_instante_utc(): void
    {
        $this->assertSame('2026-09-29 12:00:00', Fuso::instanteLocal('2026-09-29', '08:00', 'America/Manaus')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 03:00:00', Fuso::instanteLocal('2026-09-29', null, 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    public function test_hoje_depende_do_fuso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 02:00:00', 'UTC'));

        $this->assertSame('2026-09-29', Fuso::hoje('America/Manaus')->toDateString());
        $this->assertSame('2026-09-30', Fuso::hoje('UTC')->toDateString());

        Carbon::setTestNow();
    }

    public function test_instante_com_offset_e_normalizado_pra_utc(): void
    {
        $this->assertSame('2026-09-29 16:00:00', Instante::normalizar('2026-09-29T12:00:00-04:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 12:00:00', Instante::normalizar('2026-09-29T12:00:00Z')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 12:00:00', Instante::normalizar('2026-09-29 12:00:00')->format('Y-m-d H:i:s'));
        $this->assertNull(Instante::normalizar(null));
    }

    public function test_posicao_com_offset_e_gravada_em_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00', 'UTC'));
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60', 'ativo' => true]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0, 'capturado_em' => '2026-09-29T12:00:00-04:00'])
            ->assertNoContent();

        $gravado = DB::table('localizacoes_historico')->where('usuario_id', $promotor->id)->value('capturado_em');
        $this->assertStringStartsWith('2026-09-29 16:00:00', (string) $gravado);
        Carbon::setTestNow();
    }

    public function test_fuso_invalido_e_recusado_no_cadastro(): void
    {
        $superadmin = Usuario::factory()->create(['user_type' => 'SUPERADMIN', 'empresa_id' => null]);
        $empresa = Empresa::factory()->create();
        Sanctum::actingAs($superadmin);

        $this->putJson("/api/superadmin/empresas/{$empresa->uuid}", ['fuso' => 'Nao/Existe'])->assertUnprocessable();
        $this->putJson("/api/superadmin/empresas/{$empresa->uuid}", ['fuso' => 'America/Manaus'])->assertOk()
            ->assertJsonPath('empresa.fuso', 'America/Manaus');
    }
}
