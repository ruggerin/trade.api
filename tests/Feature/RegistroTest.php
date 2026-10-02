<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\MotivoResolucaoAlerta;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tela "Registros" (docs/44-TELA-REGISTROS.md) — lista genérica e crua de VisitaRegistro,
 * filtrável por query param.
 */
class RegistroTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
    }

    private function criarRegistro(array $atributos = []): VisitaRegistro
    {
        $pontoVenda = $atributos['ponto_venda'] ?? PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $promotor = $atributos['promotor'] ?? Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $tipoRegistro = $atributos['tipo_registro'] ?? TipoRegistro::create([
            'empresa_id' => $this->empresa->id,
            'descricao' => 'Ruptura',
            'eh_alerta' => true,
        ]);

        $visita = Visita::factory()->create([
            // Empresa da própria loja, não sempre $this->empresa — senão um teste que passa um
            // ponto_venda/promotor de outra empresa (isolamento) acaba criando a visita na
            // empresa errada, e o cenário de teste não representa o que diz representar.
            'empresa_id' => $pontoVenda->empresa_id,
            'ponto_venda_id' => $pontoVenda->id,
            'usuario_id' => $promotor->id,
        ]);

        $registro = VisitaRegistro::create([
            'visita_id' => $visita->id,
            'tipo_registro_id' => $tipoRegistro->id,
            'produto_auditoria_id' => $atributos['produto_auditoria_id'] ?? null,
            'ruptura' => $atributos['ruptura'] ?? false,
            'observacao' => $atributos['observacao'] ?? null,
            'cancelado_em' => $atributos['cancelado_em'] ?? null,
            'alerta_resolvido_em' => $atributos['alerta_resolvido_em'] ?? null,
            'alerta_motivo_id' => $atributos['alerta_motivo_id'] ?? null,
            'alerta_motivo_texto' => $atributos['alerta_motivo_texto'] ?? null,
        ]);

        if (isset($atributos['created_at'])) {
            // created_at não é mass-assignable (fora do $fillable de propósito) — atribuição
            // direta contorna isso, mesmo padrão usado em outros testes que precisam ancorar
            // timestamp pro filtro de período.
            $registro->created_at = $atributos['created_at'];
            $registro->save();
        }

        return $registro;
    }

    public function test_promotor_nao_acessa(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/registros')->assertForbidden();
    }

    public function test_lista_registro_com_visita_de_origem_e_status(): void
    {
        $registro = $this->criarRegistro(['observacao' => 'Gôndola vazia']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/registros')->assertOk();

        $linha = $response->json('registros.0');
        $this->assertSame($registro->uuid, $linha['id']);
        $this->assertSame($registro->visita->uuid, $linha['visita_id']);
        $this->assertSame('Gôndola vazia', $linha['observacao']);
        // eh_alerta=true (default do helper) e alerta_resolvido_em nulo => aberto.
        $this->assertSame('aberto', $linha['status']);
    }

    public function test_status_null_quando_tipo_nao_e_alerta(): void
    {
        $tipoFoto = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Foto', 'eh_alerta' => false]);
        $this->criarRegistro(['tipo_registro' => $tipoFoto]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linha = $this->getJson('/api/registros')->assertOk()->json('registros.0');
        $this->assertNull($linha['status']);
    }

    public function test_filtro_alerta_status_resolvido(): void
    {
        $this->criarRegistro(['observacao' => 'aberta']);
        $this->criarRegistro(['observacao' => 'resolvida', 'alerta_resolvido_em' => now()]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $resolvidos = $this->getJson('/api/registros?alerta_status=resolvido')->assertOk()->json('registros');
        $this->assertCount(1, $resolvidos);
        $this->assertSame('resolvida', $resolvidos[0]['observacao']);

        $abertos = $this->getJson('/api/registros?alerta_status=aberto')->assertOk()->json('registros');
        $this->assertCount(1, $abertos);
        $this->assertSame('aberta', $abertos[0]['observacao']);
    }

    public function test_filtro_por_tipo_registro_produto_e_ruptura(): void
    {
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        $tipoRuptura = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_alerta' => true]);
        $tipoAvaria = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Avaria', 'eh_alerta' => true]);

        $this->criarRegistro(['tipo_registro' => $tipoRuptura, 'produto_auditoria_id' => $produto->id, 'ruptura' => true, 'observacao' => 'alvo']);
        $this->criarRegistro(['tipo_registro' => $tipoAvaria, 'observacao' => 'outro tipo']);
        $this->criarRegistro(['tipo_registro' => $tipoRuptura, 'ruptura' => true, 'observacao' => 'outro produto']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linhas = $this->getJson("/api/registros?tipo_registro_uuid={$tipoRuptura->uuid}&produto_auditoria_uuid={$produto->uuid}&ruptura=1")
            ->assertOk()->json('registros');

        $this->assertCount(1, $linhas);
        $this->assertSame('alvo', $linhas[0]['observacao']);
    }

    public function test_filtro_por_loja_e_rede(): void
    {
        $redeAlvo = RedeLoja::factory()->create(['empresa_id' => $this->empresa->id]);
        $pdvAlvo = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'rede_loja_id' => $redeAlvo->id]);
        $pdvOutro = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);

        $this->criarRegistro(['ponto_venda' => $pdvAlvo, 'observacao' => 'na rede alvo']);
        $this->criarRegistro(['ponto_venda' => $pdvOutro, 'observacao' => 'fora']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $porRede = $this->getJson("/api/registros?rede_loja_uuid={$redeAlvo->uuid}")->assertOk()->json('registros');
        $this->assertCount(1, $porRede);
        $this->assertSame('na rede alvo', $porRede[0]['observacao']);

        $porLoja = $this->getJson("/api/registros?ponto_venda_uuid={$pdvAlvo->uuid}")->assertOk()->json('registros');
        $this->assertCount(1, $porLoja);
    }

    public function test_filtro_por_periodo(): void
    {
        $this->criarRegistro(['observacao' => 'antigo', 'created_at' => now()->subDays(10)]);
        $this->criarRegistro(['observacao' => 'recente', 'created_at' => now()]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linhas = $this->getJson('/api/registros?data_inicio='.now()->subDay()->toDateString())
            ->assertOk()->json('registros');

        $this->assertCount(1, $linhas);
        $this->assertSame('recente', $linhas[0]['observacao']);
    }

    public function test_registro_cancelado_nao_aparece(): void
    {
        $this->criarRegistro(['observacao' => 'cancelado', 'cancelado_em' => now()]);
        $this->criarRegistro(['observacao' => 'valido']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linhas = $this->getJson('/api/registros')->assertOk()->json('registros');
        $this->assertCount(1, $linhas);
        $this->assertSame('valido', $linhas[0]['observacao']);
    }

    public function test_motivo_de_resolucao_aparece_na_listagem(): void
    {
        $motivo = MotivoResolucaoAlerta::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura da indústria']);
        $this->criarRegistro([
            'observacao' => 'resolvida com motivo do catalogo',
            'alerta_resolvido_em' => now(),
            'alerta_motivo_id' => $motivo->id,
            'alerta_motivo_texto' => 'Fornecedor sem estoque essa semana',
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linha = $this->getJson('/api/registros')->assertOk()->json('registros.0');
        $this->assertSame('Ruptura da indústria', $linha['motivo']['descricao']);
        $this->assertSame('Fornecedor sem estoque essa semana', $linha['motivo_texto']);
    }

    public function test_isolamento_por_empresa(): void
    {
        $this->criarRegistro(['observacao' => 'da minha empresa']);

        $outraEmpresa = Empresa::factory()->create();
        $pdvOutraEmpresa = PontoVenda::factory()->create(['empresa_id' => $outraEmpresa->id]);
        $promotorOutraEmpresa = Usuario::factory()->promotor()->create(['empresa_id' => $outraEmpresa->id]);
        $tipoOutraEmpresa = TipoRegistro::create(['empresa_id' => $outraEmpresa->id, 'descricao' => 'Ruptura', 'eh_alerta' => true]);
        $this->criarRegistro([
            'ponto_venda' => $pdvOutraEmpresa,
            'promotor' => $promotorOutraEmpresa,
            'tipo_registro' => $tipoOutraEmpresa,
            'observacao' => 'de outra empresa',
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $linhas = $this->getJson('/api/registros')->assertOk()->json('registros');
        $this->assertCount(1, $linhas);
        $this->assertSame('da minha empresa', $linhas[0]['observacao']);
    }
}
