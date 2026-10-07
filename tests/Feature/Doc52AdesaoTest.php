<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\Adesao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/52-LOG-DE-ACESSO-E-ADESAO.md — adesão medida por USO (um registro por usuário × dia × app),
 * não por login (o token não expira). Recorte do time (ADMIN/GESTOR) e da carteira (SUPERADMIN).
 */
class Doc52AdesaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        // Quinta-feira, 01/10/2026, 15:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-01 15:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create(['fuso' => 'America/Manaus']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function acesso(Usuario $u, string $data, string $app): void
    {
        DB::table('acessos_diarios')->insert([
            'usuario_id' => $u->id, 'empresa_id' => $u->empresa_id, 'user_type' => $u->user_type->value,
            'app' => $app, 'data' => $data, 'created_at' => now(),
        ]);
    }

    private function linhas(Usuario $u): array
    {
        return DB::table('acessos_diarios')->where('usuario_id', $u->id)->orderBy('app')->get(['app', 'data'])
            ->map(fn ($l) => $l->app.' '.substr((string) $l->data, 0, 10))->all();
    }

    // ——— Gravação ———

    public function test_uso_vira_uma_linha_por_dia_e_app_sem_duplicar(): void
    {
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/usuarios', ['X-Client' => 'admin'])->assertOk();
        $this->getJson('/api/usuarios', ['X-Client' => 'admin'])->assertOk();
        $this->getJson('/api/auth/me', ['X-Client' => 'mobile'])->assertOk();

        $this->assertSame(['ADMIN 2026-10-01', 'MOBILE 2026-10-01'], $this->linhas($admin));
    }

    public function test_horario_do_ultimo_acesso_avanca_de_5_em_5_minutos(): void
    {
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);
        $horario = fn () => $this->getJson('/api/usuarios', ['X-Client' => 'admin'])->json('usuarios.0.acesso.admin_horario');

        $this->getJson('/api/auth/me', ['X-Client' => 'admin'])->assertOk(); // 15:00
        Carbon::setTestNow(Carbon::parse('2026-10-01 15:03:00', 'UTC'));
        $this->assertSame('2026-10-01T15:00:00+00:00', $horario(), 'dentro de 5 min não regrava');

        Carbon::setTestNow(Carbon::parse('2026-10-01 15:09:00', 'UTC'));
        $this->getJson('/api/auth/me', ['X-Client' => 'admin'])->assertOk();
        $this->assertSame('2026-10-01T15:09:00+00:00', $horario());
        $this->assertSame('2026-10-01T15:09:00+00:00', $this->getJson('/api/usuarios')->json('usuarios.0.acesso.ultimo_horario'));
        $this->assertSame(['ADMIN 2026-10-01'], $this->linhas($admin), 'continua uma linha por dia');
    }

    public function test_sem_header_o_promotor_conta_como_app(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/auth/me')->assertOk();

        $this->assertSame(['MOBILE 2026-10-01'], $this->linhas($promotor));
    }

    public function test_posicao_mandada_em_segundo_plano_nao_conta_como_uso(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60', 'ativo' => true]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0])->assertNoContent();

        $this->assertSame([], $this->linhas($promotor));
    }

    public function test_requisicao_negada_nao_conta(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/superadmin/empresas')->assertForbidden();

        $this->assertSame([], $this->linhas($promotor));
    }

    public function test_dia_e_o_da_empresa(): void
    {
        // 23:30 de 01/10 em Manaus = 03:30 UTC de 02/10.
        Carbon::setTestNow(Carbon::parse('2026-10-02 03:30:00', 'UTC'));
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/auth/me', ['X-Client' => 'admin'])->assertOk();

        $this->assertSame(['ADMIN 2026-10-01'], $this->linhas($admin));
    }

    // ——— "Sumiu" (docs/52 §6 decisão 1) ———

    public function test_promotor_sumiu_depois_de_3_dias_uteis_sem_usar(): void
    {
        $hoje = Carbon::parse('2026-10-05'); // segunda

        // Sexta → segunda: fim de semana não conta, 0 dia útil perdido.
        $this->assertFalse(Adesao::sumido(UserType::PROMOTOR, Carbon::parse('2026-10-02'), $hoje));
        // Quarta → segunda: perdeu quinta e sexta (2).
        $this->assertFalse(Adesao::sumido(UserType::PROMOTOR, Carbon::parse('2026-09-30'), $hoje));
        // Terça → segunda: perdeu quarta, quinta e sexta (3).
        $this->assertTrue(Adesao::sumido(UserType::PROMOTOR, Carbon::parse('2026-09-29'), $hoje));
        // Nunca usou: não afirma nada.
        $this->assertFalse(Adesao::sumido(UserType::PROMOTOR, null, $hoje));
    }

    public function test_gestor_e_admin_sumiram_depois_de_7_dias_corridos(): void
    {
        $hoje = Carbon::parse('2026-10-09');

        $this->assertFalse(Adesao::sumido(UserType::GESTOR, Carbon::parse('2026-10-02'), $hoje)); // 6 dias perdidos
        $this->assertTrue(Adesao::sumido(UserType::ADMIN, Carbon::parse('2026-10-01'), $hoje));   // 7
    }

    // ——— Recorte do time (docs/52 §4.1) ———

    public function test_lista_de_usuarios_mostra_ultimo_acesso_por_app_frequencia_e_sumiu(): void
    {
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id, 'nome' => 'A Admin']);
        $ativo = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'B Ativo']);
        $sumido = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'C Sumido']);
        $novo = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'D Novo']);
        foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $d) {
            $this->acesso($ativo, $d, Adesao::APP_MOBILE);
        }
        $this->acesso($ativo, '2026-09-25', Adesao::APP_ADMIN);
        $this->acesso($sumido, '2026-09-24', Adesao::APP_MOBILE); // quinta → perdeu sex, seg, ter, qua
        Sanctum::actingAs($admin);

        $usuarios = collect($this->getJson('/api/usuarios', ['X-Client' => 'admin'])->assertOk()->json('usuarios'))->keyBy('nome');

        $this->assertSame('2026-09-30', $usuarios['B Ativo']['acesso']['ultimo_em']);
        $this->assertSame('2026-09-30', $usuarios['B Ativo']['acesso']['mobile_em']);
        $this->assertSame('2026-09-25', $usuarios['B Ativo']['acesso']['admin_em']);
        $this->assertSame(4, $usuarios['B Ativo']['acesso']['dias_ativos_30d']);
        $this->assertFalse($usuarios['B Ativo']['acesso']['sumido']);

        $this->assertTrue($usuarios['C Sumido']['acesso']['sumido']);
        $this->assertSame(7, $usuarios['C Sumido']['acesso']['dias_sem_acesso']);

        $this->assertNull($usuarios['D Novo']['acesso']['ultimo_em']);
        $this->assertFalse($usuarios['D Novo']['acesso']['sumido']);

        // O uso é marcado depois da resposta: a própria request da lista só aparece na próxima.
        $this->assertNull($usuarios['A Admin']['acesso']['admin_em']);
        $denovo = collect($this->getJson('/api/usuarios', ['X-Client' => 'admin'])->json('usuarios'))->keyBy('nome');
        $this->assertSame('2026-10-01', $denovo['A Admin']['acesso']['admin_em']);
    }

    // ——— Recorte da carteira (docs/52 §4.2) ———

    public function test_superadmin_ve_a_adesao_de_cada_empresa_por_janela(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]);
        $this->acesso($promotor, '2026-09-30', Adesao::APP_MOBILE);
        $this->acesso($gestor, '2026-09-10', Adesao::APP_ADMIN);
        $parada = Empresa::factory()->create();
        Usuario::factory()->promotor()->create(['empresa_id' => $parada->id]);
        Sanctum::actingAs(Usuario::factory()->superadmin()->create());

        $empresas = collect($this->getJson('/api/superadmin/empresas')->assertOk()->json('empresas'))->keyBy('id');

        $adesao = $empresas[$this->empresa->uuid]['adesao'];
        $this->assertSame('2026-09-30', $adesao['ultima_atividade']);
        $this->assertSame(2, $adesao['usuarios_ativos']);
        $this->assertSame(['dias' => 7, 'usuarios' => 1, 'mobile' => 1, 'admin' => 0], $adesao['janelas'][0]);
        $this->assertSame(['dias' => 30, 'usuarios' => 2, 'mobile' => 1, 'admin' => 1], $adesao['janelas'][1]);

        $this->assertNull($empresas[$parada->uuid]['adesao']['ultima_atividade']);
        $this->assertSame(0, $empresas[$parada->uuid]['adesao']['janelas'][2]['usuarios']);

        $this->getJson("/api/superadmin/empresas/{$this->empresa->uuid}")->assertOk()
            ->assertJsonPath('uso.adesao.janelas.1.usuarios', 2);
    }
}
