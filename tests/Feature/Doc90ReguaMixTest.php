<?php

namespace Tests\Feature;

use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaMixItem;
use App\Models\VisitaRegistro;
use App\Support\MixDaVisita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/90 — régua do mix: cópia do mix da loja no check-out (nível produto, idempotente) e
 * cobertura calculada na hora contra os registros não cancelados da visita.
 */
class Doc90ReguaMixTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private Usuario $promotor;

    private SecaoAuditoria $amaciante;

    private SecaoAuditoria $lavaRoupas;

    /** @var array<string, ProdutoAuditoria> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $limpeza = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Limpeza']);
        $this->amaciante = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Amaciante', 'departamento_id' => $limpeza->id]);
        $this->lavaRoupas = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Lava-roupas', 'departamento_id' => $limpeza->id]);
        $desinfetantes = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Desinfetantes', 'departamento_id' => $limpeza->id]);

        foreach ([
            'azul' => ['Amaciante Azul 2L', $this->amaciante],
            'rosa' => ['Amaciante Rosa 2L', $this->amaciante],
            'po' => ['Sabão em pó 1kg', $this->lavaRoupas],
            'liquido' => ['Sabão líquido 3L', $this->lavaRoupas],
            'pinho' => ['Desinfetante Pinho 1L', $desinfetantes],
        ] as $chave => [$descricao, $secao]) {
            $this->p[$chave] = ProdutoAuditoria::factory()->create([
                'empresa_id' => $this->empresa->id, 'descricao' => $descricao, 'secao_id' => $secao->id, 'departamento_id' => $limpeza->id,
            ]);
        }
    }

    private function noMix(array $colunas, ?Usuario $quem = null, $quando = null): SortimentoPontoVenda
    {
        $item = SortimentoPontoVenda::create(['ponto_venda_id' => $this->pdv->id, 'usuario_id' => $quem?->id, ...$colunas]);
        if ($quando) {
            $item->forceFill(['created_at' => $quando])->save();
        }

        return $item;
    }

    private function registro(Visita $visita, TipoRegistro $tipo, array $dados): VisitaRegistro
    {
        return VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id, ...$dados]);
    }

    public function test_copia_o_mix_no_checkout_e_calcula_a_cobertura(): void
    {
        $this->noMix(['tipo_item' => 'SECAO', 'secao_id' => $this->amaciante->id], null, now()->subDays(10));
        $this->noMix(['tipo_item' => 'PRODUTO', 'produto_id' => $this->p['po']->id], null, now()->subDays(10));
        $this->noMix(['tipo_item' => 'PRODUTO', 'produto_id' => $this->p['pinho']->id], null, now()->subDays(10));
        // Pendente de aprovação fica de fora.
        $this->noMix(['tipo_item' => 'PRODUTO', 'produto_id' => $this->p['liquido']->id, 'status_aprovacao' => 'PENDENTE']);

        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
            'inicio_data' => now()->subHour(),
        ]);
        // O promotor adicionou o sabão líquido durante a visita: entra marcado.
        $this->noMix(['tipo_item' => 'PRODUTO', 'produto_id' => $this->p['liquido']->id], $this->promotor, now()->subMinutes(30));

        Sanctum::actingAs($this->promotor);
        $this->patchJson("/api/visitas/{$visita->uuid}/checkout", [
            'latitude' => $this->pdv->latitude, 'longitude' => $this->pdv->longitude, 'fim_em' => now()->toIso8601String(),
        ])->assertOk();

        $copia = VisitaMixItem::where('visita_id', $visita->id)->get()->keyBy('produto_id');
        $this->assertCount(5, $copia);
        $this->assertSame('SECAO', $copia[$this->p['azul']->id]->origem_tipo->value);
        $this->assertSame('Amaciante', $copia[$this->p['azul']->id]->secao_descricao);
        $this->assertTrue($copia[$this->p['liquido']->id]->adicionado_na_visita);
        $this->assertFalse($copia[$this->p['po']->id]->adicionado_na_visita);

        // Mix muda depois e a visita é reprocessada: a cópia não muda.
        SortimentoPontoVenda::where('ponto_venda_id', $this->pdv->id)->delete();
        MixDaVisita::gravar($visita->fresh());
        $this->assertSame(5, VisitaMixItem::where('visita_id', $visita->id)->count());

        // Registros: campo Mix (azul presente, rosa ausente), sabão em pó no produto, seção
        // Lava-roupas sem produto (cobre o líquido pelo recorte) e um cancelado que não conta.
        $auditoria = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Auditoria Amaciante']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $auditoria->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $this->amaciante->id,
        ]);
        $simples = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ponto extra']);
        $this->registro($visita, $auditoria, ['valores_campos' => ['mix' => json_encode(['presentes' => [$this->p['azul']->uuid], 'ausentes' => [$this->p['rosa']->uuid]])]]);
        $this->registro($visita, $simples, ['produto_auditoria_id' => $this->p['po']->id, 'tipo_vinculo' => 'PRODUTO']);
        $this->registro($visita, $simples, ['tipo_vinculo' => 'SECAO', 'secao_id' => $this->lavaRoupas->id]);
        $this->registro($visita, $simples, ['produto_auditoria_id' => $this->p['pinho']->id, 'tipo_vinculo' => 'PRODUTO', 'cancelado_em' => now()]);

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $regua = $this->getJson("/api/visitas/{$visita->uuid}")->assertOk()->json('regua_mix');

        $this->assertSame(5, $regua['total']);
        $this->assertSame(['registrado' => 2, 'recorte' => 1, 'ruptura' => 1, 'sem_registro' => 1], $regua['contagem']);
        $this->assertSame(80, $regua['percentual']);
        $porProduto = collect($regua['itens'])->keyBy('descricao');
        $this->assertSame('ruptura', $porProduto['Amaciante Rosa 2L']['situacao']);
        $this->assertSame('recorte', $porProduto['Sabão líquido 3L']['situacao']);
        $this->assertSame(['Ponto extra (seção Lava-roupas)'], $porProduto['Sabão líquido 3L']['onde']);
        $this->assertSame('sem_registro', $porProduto['Desinfetante Pinho 1L']['situacao']);
        $this->assertTrue($porProduto['Sabão líquido 3L']['adicionado_na_visita']);
    }

    public function test_visita_sem_copia_nao_mostra_a_regua(): void
    {
        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
        ]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->assertNull($this->getJson("/api/visitas/{$visita->uuid}")->assertOk()->json('regua_mix'));
    }
}
