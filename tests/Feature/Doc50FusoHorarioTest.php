<?php

namespace Tests\Feature;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\Fuso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Validação do docs/50-SUPORTE-MULTIPLOS-FUSOS-HORARIOS.md, seção por seção — cada teste é a
 * prova de uma regra do doc. Complementa o FusoTest (helpers) e o RotaDoDiaTest (corte de dia).
 */
class Doc50FusoHorarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ——— §2 princípio 1 / §3: instante é sempre UTC ———

    public function test_s3_aplicacao_roda_em_utc_e_o_config_versionado_e_utc(): void
    {
        // O remendo pra Manaus no servidor de teste nunca pode ser commitado (§3, drift).
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertMatchesRegularExpression("/'timezone'\\s*=>\\s*'UTC'/", file_get_contents(config_path('app.php')));
    }

    public function test_s2_checkout_forcado_com_offset_grava_em_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00', 'UTC'));
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'inicio_data' => Carbon::parse('2026-09-29 13:00:00', 'UTC'),
        ]);
        Sanctum::actingAs($admin);

        // 13:30 em Manaus = 17:30 UTC.
        $this->postJson("/api/visitas/{$visita->uuid}/forcar-checkout", [
            'fim_data' => '2026-09-29T13:30:00-04:00',
            'motivo' => 'Esqueceu de fazer checkout.',
        ])->assertOk();

        $this->assertSame('2026-09-29 17:30:00', $this->bruto('visitas', $visita->id, 'fim_data'));
    }

    public function test_s2_correcao_de_horario_com_offset_grava_em_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00', 'UTC'));
        [$empresa, $admin, $promotor, $pdv] = $this->cenario();
        $visita = Visita::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse('2026-09-29 13:00:00', 'UTC'),
            'fim_data' => Carbon::parse('2026-09-29 14:00:00', 'UTC'),
        ]);
        Sanctum::actingAs($admin);

        // Admin em Manaus corrige a entrada pra 08:00 local = 12:00 UTC.
        $this->patchJson("/api/visitas/{$visita->uuid}/horarios", [
            'inicio_data' => '2026-09-29T08:00:00-04:00',
            'motivo' => 'Chegou mais cedo, o app atrasou.',
        ])->assertOk();

        $this->assertSame('2026-09-29 12:00:00', $this->bruto('visitas', $visita->id, 'inicio_data'));
    }

    // ——— §4.1: duração não tem fuso ———

    public function test_s4_1_duracao_e_a_mesma_em_qualquer_fuso(): void
    {
        $chegada = Carbon::parse('2026-09-29T08:00:00-04:00'); // Manaus
        $saida = Carbon::parse('2026-09-29T10:15:00-03:00');   // o mesmo dia, visto de Brasília

        $this->assertSame(75.0, $chegada->diffInMinutes($saida));
        $this->assertSame(75.0, $chegada->copy()->utc()->diffInMinutes($saida->copy()->setTimezone('America/Rio_Branco')));
    }

    // ——— §4.2: horário marcado é da loja (herda da empresa) ———

    public function test_s4_2_loja_no_acre_dentro_de_empresa_de_sao_paulo(): void
    {
        $empresa = Empresa::factory()->create(['fuso' => 'America/Sao_Paulo']);
        $loja = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fuso' => 'America/Rio_Branco']);
        $outra = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        // "08:00" marcado é 08:00 do lugar da loja: Acre UTC-5, São Paulo UTC-3.
        $this->assertSame('2026-09-29 13:00:00', Fuso::instanteLocal('2026-09-29', '08:00', Fuso::daLoja($loja))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 11:00:00', Fuso::instanteLocal('2026-09-29', '08:00', Fuso::daLoja($outra))->format('Y-m-d H:i:s'));
    }

    public function test_s4_2_fuso_invalido_gravado_cai_no_da_empresa_e_depois_no_padrao(): void
    {
        $empresa = Empresa::factory()->create(['fuso' => 'America/Manaus']);
        $loja = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        DB::table('pontos_venda')->where('id', $loja->id)->update(['fuso' => 'Lixo/Qualquer']);
        $this->assertSame('America/Manaus', Fuso::daLoja($loja->refresh()));

        DB::table('empresas')->where('id', $empresa->id)->update(['fuso' => 'Lixo/Qualquer']);
        $this->assertSame(Fuso::PADRAO, Fuso::daLoja($loja->refresh()));
    }

    // ——— §4.3: corte de dia é da empresa ———

    public function test_s4_3_dia_tem_24h_mesmo_no_fuso_de_cada_regiao(): void
    {
        foreach (['America/Sao_Paulo' => '03:00', 'America/Manaus' => '04:00', 'America/Rio_Branco' => '05:00'] as $fuso => $inicioUtc) {
            [$inicio, $fim] = Fuso::intervaloDoDia('2026-09-29', $fuso);
            $this->assertSame("2026-09-29 {$inicioUtc}:00", $inicio->format('Y-m-d H:i:s'), $fuso);
            $this->assertEqualsWithDelta(24 * 60, $inicio->diffInMinutes($fim), 1, $fuso);
        }
    }

    public function test_s4_3_dia_com_horario_de_verao_tem_23h(): void
    {
        // O Brasil não tem horário de verão desde 2019, mas o helper não pode assumir 24h fixas.
        [$inicio, $fim] = Fuso::intervaloDoDia('2026-03-08', 'America/New_York');

        $this->assertEqualsWithDelta(23 * 60, $inicio->diffInMinutes($fim), 1);
    }

    public function test_s4_3_hoje_vira_na_meia_noite_da_empresa(): void
    {
        // 23:30 em Manaus já é 03:30 do dia seguinte em UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-30 03:30:00', 'UTC'));

        $this->assertSame('2026-09-29', Fuso::hoje('America/Manaus')->toDateString());
        $this->assertSame('2026-09-30', Fuso::hoje('America/Sao_Paulo')->toDateString());
    }

    // ——— §6: cadastro do fuso ———

    public function test_s6_empresa_nova_nasce_em_brasilia_ou_no_fuso_informado(): void
    {
        Sanctum::actingAs(Usuario::factory()->superadmin()->create());

        $this->postJson('/api/superadmin/empresas', $this->novaEmpresa('11111111000111'))->assertCreated()
            ->assertJsonPath('empresa.fuso', 'America/Sao_Paulo');
        $this->postJson('/api/superadmin/empresas', $this->novaEmpresa('22222222000122') + ['fuso' => 'America/Manaus'])->assertCreated()
            ->assertJsonPath('empresa.fuso', 'America/Manaus');
        $this->postJson('/api/superadmin/empresas', $this->novaEmpresa('33333333000133') + ['fuso' => 'Manaus'])->assertUnprocessable()
            ->assertJsonValidationErrors('fuso');
    }

    public function test_s6_admin_acerta_o_fuso_da_propria_empresa(): void
    {
        [$empresa, $admin] = $this->cenario();
        Sanctum::actingAs($admin);

        $this->putJson('/api/empresa', ['fuso' => 'America/Manaus'])->assertOk()->assertJsonPath('empresa.fuso', 'America/Manaus');
        $this->assertSame('America/Manaus', $empresa->refresh()->fuso);
        $this->putJson('/api/empresa', ['fuso' => 'Nao/Existe'])->assertUnprocessable();
    }

    public function test_s6_loja_grava_fuso_proprio_e_volta_a_herdar(): void
    {
        [$empresa, $admin, , $pdv] = $this->cenario();
        Sanctum::actingAs($admin);

        $this->putJson("/api/pontos-venda/{$pdv->uuid}", ['fuso' => 'America/Rio_Branco'])->assertOk()
            ->assertJsonPath('ponto_venda.fuso', 'America/Rio_Branco');
        $this->putJson("/api/pontos-venda/{$pdv->uuid}", ['fuso' => null])->assertOk()
            ->assertJsonPath('ponto_venda.fuso', null);
        $this->assertSame($empresa->refresh()->fuso, Fuso::daLoja($pdv->refresh()));
        $this->putJson("/api/pontos-venda/{$pdv->uuid}", ['fuso' => 'UTC-4'])->assertUnprocessable();
    }

    // ——— apoio ———

    /** @return array{0: Empresa, 1: Usuario, 2: Usuario, 3: PontoVenda} */
    private function cenario(): array
    {
        $empresa = Empresa::factory()->create();

        return [
            $empresa,
            Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]),
            Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]),
            PontoVenda::factory()->create(['empresa_id' => $empresa->id]),
        ];
    }

    /** O valor como está no banco (hora "de parede"), sem passar pelo cast do model. */
    private function bruto(string $tabela, int $id, string $coluna): string
    {
        return substr((string) DB::table($tabela)->where('id', $id)->value($coluna), 0, 19);
    }

    private function novaEmpresa(string $cnpj): array
    {
        return [
            'razao_social' => "Empresa {$cnpj}",
            'nome_fantasia' => "Empresa {$cnpj}",
            'cnpj' => $cnpj,
            'plano' => 'PRO',
            'admin_nome' => 'Admin',
            'admin_email' => "admin{$cnpj}@teste.com",
            'admin_senha' => 'senha-forte-123',
        ];
    }
}
