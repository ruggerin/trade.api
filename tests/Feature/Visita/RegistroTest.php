<?php

namespace Tests\Feature\Visita;

use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `tipo_registro` deixou de ser um enum fixo (FOTO/RUPTURA/OBSERVACAO) e virou uma lista
 * customizável por empresa (`TipoRegistro`, com campos extras próprios) — ver
 * docs/01-MODELO-DE-DADOS.md e TipoRegistroController. Toda empresa nasce com "Foto",
 * "Ruptura" e "Observação" pré-cadastrados (EmpresaFactory::configure / TipoRegistro::seedPadrao),
 * mesmo comportamento de antes, só que editável/extensível agora.
 */
class RegistroTest extends TestCase
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

    private function tipoRegistroUuid(Empresa $empresa, string $descricao): string
    {
        return TipoRegistro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('descricao', $descricao)
            ->value('uuid');
    }

    // GD não está habilitado neste ambiente (UploadedFile::fake()->image() precisa dela pra
    // gerar a imagem) — em vez disso, sobe um PNG 1x1 real e mínimo, só com bytes válidos o
    // bastante pra passar na regra `image` do StoreVisitaRegistroRequest.
    private function imagemFake(): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'registro').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'registro.png', 'image/png', null, true);
    }

    public function test_registro_tipo_foto_sem_imagem_retorna_422(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
        ])->assertStatus(422)->assertJsonValidationErrors('imagem');
    }

    public function test_registro_geral_do_tipo_foto_sem_produto_vinculado(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagem' => $this->imagemFake(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('registro.produto_auditoria', null)
            ->assertJsonPath('registro.tipo_registro.descricao', 'Foto');

        $this->assertNotNull($response->json('registro.imagem_url'));

        $visita = Visita::where('uuid', $visitaUuid)->first();
        Storage::disk('local')->assertExists($visita->registros()->first()->imagem_path);
    }

    public function test_registro_geral_aceita_marcacao_de_momento_antes_ou_depois(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $fotoUuid = $this->tipoRegistroUuid($empresa, 'Foto');

        $antes = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $fotoUuid,
            'momento' => 'ANTES',
            'imagem' => $this->imagemFake(),
        ]);
        $antes->assertCreated()->assertJsonPath('registro.momento', 'ANTES');

        $depois = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $fotoUuid,
            'momento' => 'DEPOIS',
            'imagem' => $this->imagemFake(),
        ]);
        $depois->assertCreated()->assertJsonPath('registro.momento', 'DEPOIS');
    }

    public function test_registro_sem_momento_continua_valido(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagem' => $this->imagemFake(),
        ])->assertCreated()->assertJsonPath('registro.momento', null);
    }

    public function test_momento_e_aceito_junto_com_produto_vinculado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::create([
            'empresa_id' => $empresa->id,
            'descricao' => 'Produto Teste',
            'propriedade' => 'PROPRIA',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Ruptura'),
            'produto_auditoria_uuid' => $produto->uuid,
            'ruptura' => true,
            'momento' => 'ANTES',
        ]);

        $response->assertCreated()->assertJsonPath('registro.momento', 'ANTES');
    }

    public function test_mesmo_produto_aceita_varios_registros_livremente(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::create([
            'empresa_id' => $empresa->id,
            'descricao' => 'Amaciante Carinho',
            'propriedade' => 'PROPRIA',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $tipoObservacao = $this->tipoRegistroUuid($empresa, 'Observação');
        $tipoPontoExtra = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Ponto extra', 'permite_vincular_catalogo' => false,
        ]);

        // Antes, depois, e um terceiro registro de outro tipo — tudo pro mesmo produto, na
        // mesma visita, sem nenhuma trava de quantidade nem de combinação com momento.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoObservacao, 'produto_auditoria_uuid' => $produto->uuid, 'momento' => 'ANTES',
        ])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoObservacao, 'produto_auditoria_uuid' => $produto->uuid, 'momento' => 'DEPOIS',
        ])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoPontoExtra->uuid, 'produto_auditoria_uuid' => $produto->uuid,
        ])->assertCreated();

        $this->assertDatabaseCount('visita_registros', 3);
        $this->assertEquals(3, \App\Models\VisitaRegistro::where('produto_auditoria_id', $produto->id)->count());
    }

    public function test_momento_invalido_e_rejeitado(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'momento' => 'DURANTE',
            'imagem' => $this->imagemFake(),
        ])->assertStatus(422)->assertJsonValidationErrors('momento');
    }

    public function test_registro_de_ruptura_vinculado_a_produto(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::create([
            'empresa_id' => $empresa->id,
            'descricao' => 'Produto Teste',
            'propriedade' => 'PROPRIA',
        ]);

        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Ruptura'),
            'produto_auditoria_uuid' => $produto->uuid,
            'ruptura' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('registro.ruptura', true)
            ->assertJsonPath('registro.produto_auditoria.id', $produto->uuid);
    }

    public function test_registro_em_visita_finalizada_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertOk();

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'Loja fechada',
        ])->assertStatus(422);
    }

    public function test_promotor_nao_registra_em_visita_de_outro_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $dono = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        $visitaUuid = $this->abrirVisita($dono, $pdv);

        Sanctum::actingAs($outroPromotor);
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'Não deveria conseguir',
        ])->assertForbidden();
    }

    public function test_imagem_do_registro_exige_autenticacao(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $registroUuid = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagem' => $this->imagemFake(),
        ])->json('registro.id');

        // Sanctum::actingAs() injeta o usuário direto no guard (não depende de header) — sem
        // resetar, a próxima chamada "sem token" continuaria autenticada como $promotor.
        $this->app['auth']->forgetGuards();

        // Sem token.
        $this->getJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/imagem")->assertUnauthorized();

        // Com token do dono, funciona.
        Sanctum::actingAs($promotor);
        $this->get("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/imagem")->assertOk();
    }

    public function test_campo_customizado_obrigatorio_e_validado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'quantidade', 'rotulo' => 'Quantidade',
            'tipo_campo' => 'NUMERO', 'obrigatorio' => true, 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'valor', 'rotulo' => 'Valor',
            'tipo_campo' => 'MOEDA', 'obrigatorio' => false, 'ordem' => 1,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // Sem o campo obrigatório "quantidade".
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['valor' => '199.90'],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.quantidade');

        // Com "quantidade" preenchido, mas com um valor não numérico.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['quantidade' => 'cinco'],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.quantidade');

        // Válido.
        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['quantidade' => '5', 'valor' => '199.90'],
        ]);
        $response->assertCreated()->assertJsonPath('registro.valores_campos.quantidade', '5');
    }

    public function test_campo_multipla_escolha_rejeita_valor_fora_das_opcoes(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ação da concorrência']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'estado', 'rotulo' => 'Estado da gôndola',
            'tipo_campo' => 'MULTIPLA_ESCOLHA', 'opcoes' => ['Boa', 'Regular', 'Ruim'], 'obrigatorio' => true, 'ordem' => 0,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['estado' => 'Péssima'],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.estado');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['estado' => 'Regular'],
        ])->assertCreated();
    }

    public function test_registro_vinculado_a_secao_inteira(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Bebidas']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Cervejas', 'departamento_id' => $departamento->id]);
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Ação da concorrência', 'permite_vincular_catalogo' => true,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $secao->uuid,
            'observacao' => 'Concorrente com desconto agressivo',
        ]);

        $response->assertCreated()->assertJsonPath('registro.secao.id', $secao->uuid);
    }

    // ---- Idempotência (fila offline de envio pode retransmitir o mesmo registro) ----

    public function test_reenvio_com_mesma_idempotency_key_nao_duplica_o_registro(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $chave = (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'Prateleira vazia',
            'idempotency_key' => $chave,
        ];

        $primeira = $this->postJson("/api/visitas/{$visitaUuid}/registros", $payload);
        $primeira->assertCreated();

        $segunda = $this->postJson("/api/visitas/{$visitaUuid}/registros", $payload);
        $segunda->assertOk();

        $this->assertSame($primeira->json('registro.id'), $segunda->json('registro.id'));
        $this->assertDatabaseCount('visita_registros', 1);
    }

    public function test_idempotency_key_diferente_cria_registros_distintos(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $tipoUuid = $this->tipoRegistroUuid($empresa, 'Observação');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Um', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Dois', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertCreated();

        $this->assertDatabaseCount('visita_registros', 2);
    }

    public function test_registro_sem_idempotency_key_continua_funcionando(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $tipoUuid = $this->tipoRegistroUuid($empresa, 'Observação');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", ['tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Um'])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", ['tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Dois'])->assertCreated();

        $this->assertDatabaseCount('visita_registros', 2);
    }

    // ---- Validação de uuids referenciados (escopo por empresa) ----

    public function test_tipo_registro_de_outra_empresa_retorna_422_em_vez_de_quebrar(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $tipoDeOutraEmpresa = $this->tipoRegistroUuid($outraEmpresa, 'Foto');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoDeOutraEmpresa,
            'observacao' => 'Não deveria salvar',
        ])->assertStatus(422)->assertJsonValidationErrors('tipo_registro_uuid');

        $this->assertDatabaseCount('visita_registros', 0);
    }

    public function test_produto_secao_departamento_marca_invalidos_retornam_422(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $tipoUuid = $this->tipoRegistroUuid($empresa, 'Observação');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid,
            'produto_auditoria_uuid' => 'uuid-que-nao-existe',
            'observacao' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('produto_auditoria_uuid');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => 'uuid-que-nao-existe',
            'observacao' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('secao_uuid');

        $this->assertDatabaseCount('visita_registros', 0);
    }
}
