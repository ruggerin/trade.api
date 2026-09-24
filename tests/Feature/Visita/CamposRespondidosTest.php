<?php

namespace Tests\Feature\Visita;

use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `campos_respondidos` no detalhe da visita (GET /visitas/{uuid}) — rótulo resolvido e valor
 * formatado por tipo_campo, em vez do `valores_campos` cru (chave técnica + JSON de SORTIMENTO
 * sem tratamento). Ver App\Support\FormatadorValoresCampos.
 */
class CamposRespondidosTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisita(Usuario $promotor, PontoVenda $pdv): string
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    public function test_campo_booleano_formata_sim_nao(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'promocionado', 'rotulo' => 'Promocionado?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['promocionado' => '1'],
        ])->assertCreated();

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/visitas/{$visitaUuid}")->assertOk();

        $campo = $response->json('visita.registros.0.campos_respondidos.0');
        $this->assertSame('promocionado', $campo['chave']);
        $this->assertSame('Promocionado?', $campo['rotulo']);
        $this->assertSame('BOOLEANO', $campo['tipo_campo']);
        $this->assertSame('Sim', $campo['valor']);
    }

    public function test_campo_sortimento_resolve_nome_dos_produtos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes']);
        $presente = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id, 'descricao' => 'Amaciante Confort 2L']);
        $ausente = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id, 'descricao' => 'Amaciante Downy 2L']);
        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $presente->id]);
        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $ausente->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix_produtos', 'rotulo' => 'Mix de amaciantes',
            'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $secao->id,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix_produtos' => json_encode(['presentes' => [$presente->uuid], 'ausentes' => [$ausente->uuid]])],
        ])->assertCreated();

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/visitas/{$visitaUuid}")->assertOk();

        $campo = $response->json('visita.registros.0.campos_respondidos.0');
        $this->assertSame('SORTIMENTO', $campo['tipo_campo']);
        $this->assertNull($campo['valor']);
        $this->assertSame('Amaciante Confort 2L', $campo['sortimento']['presentes'][0]['descricao']);
        $this->assertSame($presente->uuid, $campo['sortimento']['presentes'][0]['id']);
        $this->assertSame('Amaciante Downy 2L', $campo['sortimento']['ausentes'][0]['descricao']);
    }

    public function test_campo_nao_respondido_nao_aparece(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'promocionado', 'rotulo' => 'Promocionado?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0, 'obrigatorio' => false,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
        ])->assertCreated();

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/visitas/{$visitaUuid}")->assertOk();

        $this->assertSame([], $response->json('visita.registros.0.campos_respondidos'));
    }
}
