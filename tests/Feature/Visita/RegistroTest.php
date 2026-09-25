<?php

namespace Tests\Feature\Visita;

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
        ])->assertStatus(422)->assertJsonValidationErrors('imagens');
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
            'imagens' => [$this->imagemFake()],
        ]);

        $response->assertCreated()
            ->assertJsonPath('registro.produto_auditoria', null)
            ->assertJsonPath('registro.tipo_registro.descricao', 'Foto');

        $this->assertNotNull($response->json('registro.imagens.0.url'));

        $visita = Visita::where('uuid', $visitaUuid)->first();
        $imagem = $visita->registros()->first()->imagens()->first();
        $this->assertNotNull($imagem);
        Storage::disk('local')->assertExists($imagem->caminho);
    }

    public function test_registro_aceita_varias_imagens_e_foto_ja_existente_da_mesma_visita(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // Primeiro registro sobe 2 fotos novas (ex.: expositor instalado em 2 lugares).
        $primeiro = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagens' => [$this->imagemFake(), $this->imagemFake()],
        ]);
        $primeiro->assertCreated()->assertJsonCount(2, 'registro.imagens');

        // Segundo registro reaproveita a primeira foto do primeiro (evidência compartilhada
        // entre respostas) sem reenviar arquivo.
        $imagemExistenteUuid = $primeiro->json('registro.imagens.0.id');
        $segundo = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'Mesma prateleira',
            'imagens_existentes_uuids' => [$imagemExistenteUuid],
        ]);
        $segundo->assertCreated()
            ->assertJsonCount(1, 'registro.imagens')
            ->assertJsonPath('registro.imagens.0.id', $imagemExistenteUuid);

        $this->assertDatabaseCount('imagens_registro', 2);
    }

    public function test_imagem_existente_de_outra_visita_retorna_422(): void
    {
        Storage::fake('local');

        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        $visitaUuidA = $this->abrirVisita($promotor, $pdv);
        $imagemDeOutraVisita = $this->postJson("/api/visitas/{$visitaUuidA}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagens' => [$this->imagemFake()],
        ])->json('registro.imagens.0.id');

        $this->patchJson("/api/visitas/{$visitaUuidA}/checkout", ['latitude' => $pdv->latitude, 'longitude' => $pdv->longitude]);
        $visitaUuidB = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuidB}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Observação'),
            'observacao' => 'x',
            'imagens_existentes_uuids' => [$imagemDeOutraVisita],
        ])->assertStatus(422)->assertJsonValidationErrors('imagens_existentes_uuids.0');
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

        // Dois registros do mesmo tipo e um terceiro de outro tipo — tudo pro mesmo produto, na
        // mesma visita, sem nenhuma trava de quantidade.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoObservacao, 'produto_auditoria_uuid' => $produto->uuid, 'observacao' => 'Antes',
        ])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoObservacao, 'produto_auditoria_uuid' => $produto->uuid, 'observacao' => 'Depois',
        ])->assertCreated();
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoPontoExtra->uuid, 'produto_auditoria_uuid' => $produto->uuid,
        ])->assertCreated();

        $this->assertDatabaseCount('visita_registros', 3);
        $this->assertEquals(3, \App\Models\VisitaRegistro::where('produto_auditoria_id', $produto->id)->count());
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

        $imagemUuid = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $this->tipoRegistroUuid($empresa, 'Foto'),
            'imagens' => [$this->imagemFake()],
        ])->json('registro.imagens.0.id');

        // Sanctum::actingAs() injeta o usuário direto no guard (não depende de header) — sem
        // resetar, a próxima chamada "sem token" continuaria autenticada como $promotor.
        $this->app['auth']->forgetGuards();

        // Sem token.
        $this->getJson("/api/visitas/{$visitaUuid}/imagens/{$imagemUuid}")->assertUnauthorized();

        // Com token do dono, funciona.
        Sanctum::actingAs($promotor);
        $this->get("/api/visitas/{$visitaUuid}/imagens/{$imagemUuid}")->assertOk();
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

    public function test_campo_booleano_e_data_validam_formato(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'promocionado', 'rotulo' => 'Promocionado?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'validade', 'rotulo' => 'Validade',
            'tipo_campo' => 'DATA', 'ordem' => 1,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['promocionado' => 'sim', 'validade' => '31/02/2026'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['valores_campos.promocionado', 'valores_campos.validade']);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['promocionado' => '1', 'validade' => '15/03/2026'],
        ]);
        $response->assertCreated()
            ->assertJsonPath('registro.valores_campos.promocionado', '1')
            ->assertJsonPath('registro.valores_campos.validade', '15/03/2026');
    }

    /**
     * docs/35-LIMITE-RETROATIVO-CAMPO-DATA.md — `limite_dias_retroativos` só controla o passado;
     * data futura nunca é rejeitada por causa dele. Sem o parâmetro (campo `validade` do teste
     * acima, ou qualquer campo já cadastrado antes desta feature), continua sem limite nenhum.
     */
    public function test_campo_data_com_limite_dias_retroativos_rejeita_data_antiga_demais(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Alerta de Validade Próxima']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'dt_validade', 'rotulo' => 'Validade',
            'tipo_campo' => 'DATA', 'ordem' => 0, 'limite_dias_retroativos' => 30,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // Mais de 30 dias atrás: rejeitado.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['dt_validade' => now()->subDays(40)->format('d/m/Y')],
        ])->assertStatus(422)->assertJsonValidationErrors(['valores_campos.dt_validade']);

        // Dentro dos 30 dias: aceito.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'valores_campos' => ['dt_validade' => now()->subDays(10)->format('d/m/Y')],
        ])->assertCreated();

        // Data futura: o limite retroativo nunca bloqueia isso.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'valores_campos' => ['dt_validade' => now()->addYear()->format('d/m/Y')],
        ])->assertCreated();
    }

    public function test_campo_sortimento_aceita_produtos_do_recorte_e_rejeita_fora_dele(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes', 'departamento_id' => $departamento->id]);
        $produtoDentro = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        $produtoForaDaSecao = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);

        SortimentoPontoVenda::create([
            'ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produtoDentro->id,
        ]);
        // O PDV também tem esse produto de fora no sortimento, mas ele não faz parte do RECORTE
        // configurado no campo (seção Amaciantes) — não deveria aparecer como opção válida.
        SortimentoPontoVenda::create([
            'ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produtoForaDaSecao->id,
        ]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix de amaciantes',
            'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $secao->id,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix' => json_encode(['presentes' => [$produtoDentro->uuid], 'ausentes' => []])],
        ]);
        $response->assertCreated();

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix' => json_encode(['presentes' => [], 'ausentes' => [$produtoForaDaSecao->uuid]])],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');
    }

    public function test_campo_sortimento_rejeita_produto_em_presentes_e_ausentes_ao_mesmo_tempo(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes']);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produto->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $secao->id,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix' => json_encode(['presentes' => [$produto->uuid], 'ausentes' => [$produto->uuid]])],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');
    }

    public function test_campo_sortimento_fixo_aceita_so_produtos_da_lista_curada(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $produtoCurado = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $produtoNaoCurado = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita']);
        $campo = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 0,
            'sortimento_origem' => 'FIXO',
        ]);
        $campo->produtosFixos()->attach($produtoCurado->id);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // Fixo ignora o sortimento real do PDV — nem precisa de SortimentoPontoVenda cadastrado.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix' => json_encode(['presentes' => [$produtoCurado->uuid], 'ausentes' => []])],
        ])->assertCreated();

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['mix' => json_encode(['presentes' => [], 'ausentes' => [$produtoNaoCurado->uuid]])],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.mix');
    }

    public function test_pontuacao_calcula_percentual_de_campos_booleano_e_sortimento_que_passaram(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes']);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);
        SortimentoPontoVenda::create(['ponto_venda_id' => $pdv->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produto->id]);

        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita', 'usa_pontuacao' => true]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'promocionado', 'rotulo' => 'Promocionado?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'precificado', 'rotulo' => 'Precificado?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 1,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'ordem' => 2,
            'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO', 'sortimento_secao_id' => $secao->id,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // 2 de 3 campos scoreáveis "passaram": promocionado=Sim, mix 100% presente; precificado=Não.
        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => [
                'promocionado' => '1',
                'precificado' => '0',
                'mix' => json_encode(['presentes' => [$produto->uuid], 'ausentes' => []]),
            ],
        ]);

        $response->assertCreated()->assertJsonPath('registro.pontuacao', 67);
    }

    public function test_campo_condicional_so_e_obrigatorio_quando_condicao_e_satisfeita(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Cartaz promocional']);
        $instalou = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'instalou', 'rotulo' => 'Instalou o cartaz?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'motivo', 'rotulo' => 'Por que não instalou?',
            'tipo_campo' => 'TEXTO', 'obrigatorio' => true, 'ordem' => 1,
            'depende_de_campo_id' => $instalou->id, 'depende_de_valor' => '0',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // instalou=1 (Sim) — a condição de "motivo" (instalou=0) não é satisfeita, então "motivo"
        // não é obrigatório mesmo estando vazio.
        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['instalou' => '1'],
        ]);
        $response->assertCreated();
        $this->assertArrayNotHasKey('motivo', $response->json('registro.valores_campos') ?? []);

        // instalou=0 (Não) — agora "motivo" é obrigatório.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['instalou' => '0'],
        ])->assertStatus(422)->assertJsonValidationErrors('valores_campos.motivo');

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['instalou' => '0', 'motivo' => 'Cliente não deixou'],
        ]);
        $response->assertCreated()->assertJsonPath('registro.valores_campos.motivo', 'Cliente não deixou');
    }

    public function test_valor_de_campo_condicional_e_descartado_quando_condicao_nao_satisfeita(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Cartaz promocional']);
        $instalou = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'instalou', 'rotulo' => 'Instalou o cartaz?',
            'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'motivo', 'rotulo' => 'Por que não instalou?',
            'tipo_campo' => 'TEXTO', 'ordem' => 1,
            'depende_de_campo_id' => $instalou->id, 'depende_de_valor' => '0',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // instalou=1 — "motivo" não deveria nem ter aparecido no mobile; um valor enviado mesmo
        // assim (ex.: sobra de uma resposta anterior no formulário) é descartado, não gravado.
        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'valores_campos' => ['instalou' => '1', 'motivo' => 'Não deveria estar aqui'],
        ]);
        $response->assertCreated();
        $this->assertArrayNotHasKey('motivo', $response->json('registro.valores_campos') ?? []);
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
