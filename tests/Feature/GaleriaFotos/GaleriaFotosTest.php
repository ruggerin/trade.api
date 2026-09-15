<?php

namespace Tests\Feature\GaleriaFotos;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Grade só de fotos (não timeline de eventos, ver AtividadeController) — filtrável por período/
 * tipo de registro/departamento/seção/marca/produto/loja/rede de lojas/ramo de atividade/
 * promotor/ruptura. Ver GaleriaFotosController e docs/23-GALERIA-DE-FOTOS.md.
 */
class GaleriaFotosTest extends TestCase
{
    use RefreshDatabase;

    // GD não está habilitado neste ambiente — mesmo truque de tests/Feature/Visita/RegistroTest.
    private function imagemFake(): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'galeria').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'foto.png', 'image/png', null, true);
    }

    private function tipoRegistroUuid(Empresa $empresa, string $descricao): string
    {
        return TipoRegistro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('descricao', $descricao)
            ->value('uuid');
    }

    /**
     * Abre uma visita como o promotor dado e cria 1 registro com foto (tipo "Foto", um dos 3 de
     * fábrica — ver TipoRegistro::seedPadrao). Devolve o uuid do registro criado.
     */
    private function criarRegistroComFoto(
        Empresa $empresa,
        PontoVenda $pdv,
        Usuario $promotor,
        array $extra = [],
    ): string {
        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        return $this->postJson("/api/visitas/{$visitaUuid}/registros", array_merge([
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagens' => [$this->imagemFake()],
        ], $extra))->json('registro.id');
    }

    public function test_admin_ve_so_registros_com_foto(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor);

        // Registro sem foto (tipo "Observação", não exige imagem) — não deve aparecer na galeria.
        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid, 'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->json('visita.id');
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'Sem foto nenhuma',
        ])->assertCreated();

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/galeria-fotos')->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertNotEmpty($response->json('registros.0.registro.imagens'));
        $this->assertSame($pdv->uuid, $response->json('registros.0.ponto_venda.id'));
        $this->assertSame($pdv->fantasia, $response->json('registros.0.ponto_venda.fantasia'));
        $this->assertSame($promotor->uuid, $response->json('registros.0.usuario.id'));
    }

    public function test_meta_de_paginacao(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/galeria-fotos')->assertOk();

        $response->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 24)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_registro_cancelado_nao_aparece(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $registroUuid = $this->criarRegistroComFoto($empresa, $pdv, $promotor);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->getJson('/api/visitas')->json('visitas.0.id');

        // ADMIN sempre pode cancelar registro (promotor só com REGISTRO_CANCELAMENTO_PERMITIDO
        // ligado, ver App\Support\CancelamentoRegistro) — usa admin aqui pra não depender desse
        // parâmetro, o que importa pro teste é só "cancelado nunca aparece na galeria".
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertOk();

        $this->assertCount(0, $this->getJson('/api/galeria-fotos')->json('registros'));
    }

    public function test_filtro_por_tipo_de_registro(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = \App\Models\ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor, [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Ruptura'),
            'produto_auditoria_uuid' => $produto->uuid,
            'ruptura' => true,
        ]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        // Sem filtro, os dois aparecem — confirma que o segundo (Ruptura) também entrou na
        // galeria, condição necessária pro filtro abaixo provar algo de verdade.
        $this->assertCount(2, $this->getJson('/api/galeria-fotos')->json('registros'));

        $fotoUuid = $this->tipoRegistroUuid($empresa, 'Foto');
        $response = $this->getJson("/api/galeria-fotos?tipo_registro_uuid={$fotoUuid}")->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertSame('Foto', $response->json('registros.0.registro.tipo_registro.descricao'));
    }

    public function test_filtro_por_ruptura(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor); // ruptura=false (default)
        $this->criarRegistroComFoto($empresa, $pdv, $promotor, ['ruptura' => true]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/galeria-fotos?ruptura=1')->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertTrue($response->json('registros.0.registro.ruptura'));
    }

    public function test_filtro_por_loja(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvB = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdvA, $promotor);
        $this->criarRegistroComFoto($empresa, $pdvB, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/galeria-fotos?ponto_venda_uuid={$pdvA->uuid}")->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertSame($pdvA->uuid, $response->json('registros.0.ponto_venda.id'));
    }

    public function test_filtro_por_rede_de_lojas_e_ramo_de_atividade(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $rede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $ramo = RamoAtividade::factory()->create(['empresa_id' => $empresa->id]);
        $pdvNaRede = PontoVenda::factory()->create([
            'empresa_id' => $empresa->id, 'rede_loja_id' => $rede->id, 'ramo_atividade_id' => $ramo->id,
        ]);
        $pdvFora = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdvNaRede, $promotor);
        $this->criarRegistroComFoto($empresa, $pdvFora, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $porRede = $this->getJson("/api/galeria-fotos?rede_loja_uuid={$rede->uuid}")->assertOk();
        $this->assertCount(1, $porRede->json('registros'));
        $this->assertSame($rede->descricao, $porRede->json('registros.0.ponto_venda.rede_loja.descricao'));

        $porRamo = $this->getJson("/api/galeria-fotos?ramo_atividade_uuid={$ramo->uuid}")->assertOk();
        $this->assertCount(1, $porRamo->json('registros'));
        $this->assertSame($ramo->descricao, $porRamo->json('registros.0.ponto_venda.ramo_atividade.descricao'));
    }

    public function test_filtro_por_promotor(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotorA);
        $this->criarRegistroComFoto($empresa, $pdv, $promotorB);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/galeria-fotos?usuario_uuid={$promotorA->uuid}")->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertSame($promotorA->uuid, $response->json('registros.0.usuario.id'));
    }

    public function test_filtro_por_periodo(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $registroUuid = $this->criarRegistroComFoto($empresa, $pdv, $promotor);

        \App\Models\VisitaRegistro::withoutGlobalScopes()
            ->where('uuid', $registroUuid)
            ->update(['created_at' => now()->subDays(10)]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->assertCount(
            0,
            $this->getJson('/api/galeria-fotos?data_inicio='.now()->subDays(2)->toDateString())->json('registros'),
        );
        $this->assertCount(
            1,
            $this->getJson('/api/galeria-fotos?data_fim='.now()->subDays(2)->toDateString())->json('registros'),
        );
    }

    public function test_promotor_nao_acessa_a_galeria(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/galeria-fotos')->assertForbidden();
    }

    public function test_gestor_acessa_a_galeria(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $this->criarRegistroComFoto($empresa, $pdv, $promotor);

        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->getJson('/api/galeria-fotos')->assertOk();
    }

    public function test_isolado_por_empresa(): void
    {
        Storage::fake('local');
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);
        $pdvB = PontoVenda::factory()->create(['empresa_id' => $empresaB->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresaA->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresaB->id]);
        $this->criarRegistroComFoto($empresaA, $pdvA, $promotorA);
        $this->criarRegistroComFoto($empresaB, $pdvB, $promotorB);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/galeria-fotos')->assertOk();

        $this->assertCount(1, $response->json('registros'));
        $this->assertSame($pdvA->uuid, $response->json('registros.0.ponto_venda.id'));
    }
}
