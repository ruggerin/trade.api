<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/64-CONTROLE-DE-ACESSO-POR-TELA.md — a API barra as rotas exclusivas de cada tela, deixa
 * abertas as de leitura compartilhada (app e filtros), e a migração não tira acesso de ninguém.
 */
class Doc64AcessoPorTelaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
    }

    private function gestor(array $permissoes): Usuario
    {
        return Usuario::factory()->gestor()->comPerfil($permissoes)->create(['empresa_id' => $this->empresa->id]);
    }

    /** @return array<string, array{string, string}> */
    public static function rotasDeTela(): array
    {
        return [
            'operação do dia' => ['/api/operacao-do-dia', 'tela.operacao_dia'],
            'atividades' => ['/api/atividades', 'tela.atividades'],
            'resumo de atividades' => ['/api/atividades/resumo', 'tela.atividades'],
            'registros' => ['/api/registros', 'tela.registros'],
            'galeria' => ['/api/galeria-fotos', 'tela.registros'],
            'gerador de relatórios' => ['/api/relatorios-personalizados', 'tela.relatorios'],
            'tempo na loja' => ['/api/relatorios/tempo-na-loja', 'tela.relatorios'],
        ];
    }

    /** @dataProvider rotasDeTela */
    public function test_rota_exclusiva_da_tela_exige_a_tela(string $rota, string $tela): void
    {
        Sanctum::actingAs($this->gestor([]));
        $this->getJson($rota)->assertForbidden();

        Sanctum::actingAs($this->gestor([$tela]));
        $this->getJson($rota)->assertOk();
    }

    public function test_admin_passa_em_toda_tela(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        foreach (self::rotasDeTela() as [$rota]) {
            $this->getJson($rota)->assertOk();
        }
    }

    public function test_leitura_compartilhada_continua_aberta_sem_a_tela(): void
    {
        // Lojas, catálogo, OS e parâmetros alimentam o app e os filtros de outras telas.
        Sanctum::actingAs($this->gestor([]));
        foreach (['/api/pontos-venda', '/api/produtos-auditoria', '/api/ordens-servico', '/api/parametros', '/api/tipos-registro'] as $rota) {
            $this->getJson($rota)->assertOk();
        }
    }

    public function test_promotor_continua_lendo_o_que_o_app_usa(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/pontos-venda')->assertOk();
        $this->getJson('/api/parametros')->assertOk();
        $this->getJson('/api/atividades')->assertForbidden();
    }

    public function test_telas_do_enum_batem_com_a_migracao(): void
    {
        $migracao = require database_path('migrations/2026_10_07_000001_add_permissoes_de_tela_aos_perfis.php');
        $lista = (new \ReflectionClassConstant($migracao, 'TELAS'))->getValue();

        $this->assertSame(array_map(fn (Permissao $p) => $p->value, Permissao::telas()), $lista);
    }

    public function test_migracao_da_as_telas_a_perfis_existentes_e_cria_perfil_para_gestor_sem(): void
    {
        $migracao = require database_path('migrations/2026_10_07_000001_add_permissoes_de_tela_aos_perfis.php');
        $perfil = Perfil::create(['empresa_id' => $this->empresa->id, 'nome' => 'Antigo', 'permissoes' => ['catalogo.gerenciar'], 'ativo' => true]);
        $semPerfil = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => null]);

        $migracao->up();

        $this->assertContains('tela.relatorios', $perfil->fresh()->permissoes);
        $this->assertContains('catalogo.gerenciar', $perfil->fresh()->permissoes);
        $novo = $semPerfil->fresh()->perfil;
        $this->assertNotNull($novo);
        $this->assertSame('Acesso às telas', $novo->nome);
        $this->assertContains('tela.operacao_dia', $novo->permissoes);

        $migracao->down();
        $this->assertSame(['catalogo.gerenciar'], $perfil->fresh()->permissoes);
    }
}
