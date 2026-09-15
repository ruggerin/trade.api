<?php

namespace Tests\Feature;

use App\Models\CampanhaAuditoria;
use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#tipos-de-registro — catálogo customizável por empresa que substitui o
 * antigo enum fixo TipoRegistroVisita. Mesma permissão de catálogo (catalogo.gerenciar).
 */
class TipoRegistroTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_nasce_com_os_3_tipos_de_fabrica(): void
    {
        $empresa = Empresa::factory()->create();

        $descricoes = TipoRegistro::where('empresa_id', $empresa->id)->pluck('descricao')->sort()->values()->all();

        $this->assertSame(['Foto', 'Observação', 'Ruptura'], $descricoes);
        // Os 3 já nascem com ícone — sem o gestor precisar configurar nada de cara.
        $this->assertSame('camera', TipoRegistro::where(['empresa_id' => $empresa->id, 'descricao' => 'Foto'])->value('icone'));
    }

    public function test_admin_cria_tipo_de_registro_com_campos_customizados(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'exige_foto' => true,
            'permite_vincular_catalogo' => true,
            'campos' => [
                ['chave' => 'quantidade', 'rotulo' => 'Quantidade', 'tipo_campo' => 'NUMERO', 'obrigatorio' => true],
                ['chave' => 'valor', 'rotulo' => 'Valor', 'tipo_campo' => 'MOEDA', 'obrigatorio' => false],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.descricao', 'Ponto extra')
            ->assertJsonCount(2, 'tipo_registro.campos')
            ->assertJsonPath('tipo_registro.campos.0.chave', 'quantidade');
    }

    public function test_admin_marca_tipo_como_alerta_e_atualiza_a_flag(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Avaria', 'eh_alerta' => true,
        ]);
        $response->assertCreated()->assertJsonPath('tipo_registro.eh_alerta', true);

        $tipoUuid = $response->json('tipo_registro.id');
        $this->putJson("/api/tipos-registro/{$tipoUuid}", ['eh_alerta' => false])
            ->assertOk()->assertJsonPath('tipo_registro.eh_alerta', false);
    }

    public function test_chave_de_campo_invalida_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'campos' => [
                ['chave' => 'Quantidade Total', 'rotulo' => 'Quantidade', 'tipo_campo' => 'NUMERO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.chave');
    }

    public function test_multipla_escolha_sem_opcoes_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ação da concorrência',
            'campos' => [
                ['chave' => 'estado', 'rotulo' => 'Estado', 'tipo_campo' => 'MULTIPLA_ESCOLHA'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.opcoes');
    }

    public function test_cria_campos_booleano_e_data(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Loja Perfeita',
            'campos' => [
                ['chave' => 'promocionado', 'rotulo' => 'Produto promocionado?', 'tipo_campo' => 'BOOLEANO'],
                ['chave' => 'validade', 'rotulo' => 'Validade do produto', 'tipo_campo' => 'DATA'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.campos.0.tipo_campo', 'BOOLEANO')
            ->assertJsonPath('tipo_registro.campos.1.tipo_campo', 'DATA');
    }

    public function test_campo_condicional_referencia_chave_de_campo_anterior(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Cartaz promocional',
            'campos' => [
                ['chave' => 'instalou', 'rotulo' => 'Instalou o cartaz?', 'tipo_campo' => 'BOOLEANO'],
                [
                    'chave' => 'motivo', 'rotulo' => 'Por que não instalou?', 'tipo_campo' => 'MULTIPLA_ESCOLHA',
                    'opcoes' => ['Cliente não deixou', 'Acabou a fita', 'Outro'],
                    'depende_de_chave' => 'instalou', 'depende_de_valor' => '0',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.campos.1.depende_de_chave', 'instalou')
            ->assertJsonPath('tipo_registro.campos.1.depende_de_valor', '0');

        $this->assertDatabaseHas('campos_tipo_registro', [
            'chave' => 'motivo',
            'depende_de_campo_id' => CampoTipoRegistro::where('chave', 'instalou')->value('id'),
        ]);
    }

    public function test_campo_condicional_referenciando_chave_inexistente_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Cartaz promocional',
            'campos' => [
                [
                    'chave' => 'motivo', 'rotulo' => 'Motivo', 'tipo_campo' => 'TEXTO',
                    'depende_de_chave' => 'nao_existe', 'depende_de_valor' => '0',
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.depende_de_chave');
    }

    public function test_campo_condicional_referenciando_campo_posterior_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Cartaz promocional',
            'campos' => [
                [
                    'chave' => 'motivo', 'rotulo' => 'Motivo', 'tipo_campo' => 'TEXTO',
                    'depende_de_chave' => 'instalou', 'depende_de_valor' => '0',
                ],
                ['chave' => 'instalou', 'rotulo' => 'Instalou o cartaz?', 'tipo_campo' => 'BOOLEANO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.depende_de_chave');
    }

    public function test_cria_campo_sortimento_dinamico(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciantes', 'departamento_id' => $departamento->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Loja Perfeita',
            'campos' => [
                [
                    'chave' => 'mix', 'rotulo' => 'Mix de amaciantes', 'tipo_campo' => 'SORTIMENTO',
                    'sortimento_origem' => 'DINAMICO', 'sortimento_tipo_vinculo' => 'SECAO',
                    'sortimento_secao_uuid' => $secao->uuid, 'confirmar_ruptura_ausentes' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.campos.0.sortimento_origem', 'DINAMICO')
            ->assertJsonPath('tipo_registro.campos.0.sortimento_tipo_vinculo', 'SECAO')
            ->assertJsonPath('tipo_registro.campos.0.sortimento_secao.id', $secao->uuid)
            ->assertJsonPath('tipo_registro.campos.0.confirmar_ruptura_ausentes', true);
    }

    public function test_sortimento_dinamico_sem_tipo_vinculo_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Loja Perfeita',
            'campos' => [
                ['chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'sortimento_origem' => 'DINAMICO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.sortimento_tipo_vinculo');
    }

    public function test_cria_campo_sortimento_fixo_com_lista_curada(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Loja Perfeita',
            'campos' => [
                [
                    'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO',
                    'sortimento_origem' => 'FIXO', 'sortimento_produtos_uuids' => [$produto->uuid],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.campos.0.sortimento_origem', 'FIXO')
            ->assertJsonCount(1, 'tipo_registro.campos.0.sortimento_produtos')
            ->assertJsonPath('tipo_registro.campos.0.sortimento_produtos.0.id', $produto->uuid);
    }

    public function test_sortimento_fixo_sem_produtos_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Loja Perfeita',
            'campos' => [
                ['chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => 'SORTIMENTO', 'sortimento_origem' => 'FIXO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.sortimento_produtos_uuids');
    }

    public function test_duplicar_clona_tipo_com_campos_e_condicional(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Cartaz promocional',
            'acao_obrigatoria' => true, 'escopo_acao' => 'SEMPRE',
        ]);
        $instalou = CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'instalou', 'rotulo' => 'Instalou?', 'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'motivo', 'rotulo' => 'Motivo', 'tipo_campo' => 'TEXTO', 'ordem' => 1,
            'depende_de_campo_id' => $instalou->id, 'depende_de_valor' => '0',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/tipos-registro/{$tipo->uuid}/duplicar");

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.descricao', 'Cartaz promocional (cópia)')
            // Ação obrigatória NUNCA é copiada — decisão 6 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
            ->assertJsonPath('tipo_registro.acao_obrigatoria', false)
            ->assertJsonCount(2, 'tipo_registro.campos')
            ->assertJsonPath('tipo_registro.campos.1.depende_de_chave', 'instalou')
            ->assertJsonPath('tipo_registro.campos.1.depende_de_valor', '0');

        // A cópia é de verdade independente — mexer numa não deveria mexer na outra depois.
        $this->assertDatabaseHas('tipos_registro', ['descricao' => 'Cartaz promocional', 'id' => $tipo->id]);
        $novoUuid = $response->json('tipo_registro.id');
        $this->assertNotEquals($tipo->uuid, $novoUuid);
    }

    public function test_update_substitui_a_lista_de_campos_inteira(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'antigo', 'rotulo' => 'Antigo', 'tipo_campo' => 'TEXTO', 'ordem' => 0,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/tipos-registro/{$tipo->uuid}", [
            'campos' => [
                ['chave' => 'novo', 'rotulo' => 'Novo', 'tipo_campo' => 'NUMERO', 'obrigatorio' => true],
            ],
        ]);

        $response->assertOk()->assertJsonCount(1, 'tipo_registro.campos');
        $this->assertDatabaseMissing('campos_tipo_registro', ['chave' => 'antigo']);
        $this->assertDatabaseHas('campos_tipo_registro', ['chave' => 'novo', 'tipo_registro_id' => $tipo->id]);
    }

    public function test_gestor_sem_permissao_de_catalogo_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/tipos-registro', ['descricao' => 'Ponto extra'])->assertForbidden();
    }

    public function test_desativar_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/tipos-registro/{$tipo->uuid}")->assertNoContent();
        $this->assertDatabaseHas('tipos_registro', ['id' => $tipo->id, 'ativo' => false]);
    }

    /**
     * Ação obrigatória — ver App\Enums\EscopoAcaoTipoRegistro. Aparece na aba Ações da visita em
     * vez de só uma opção do Registro geral.
     */
    public function test_cria_acao_obrigatoria_com_escopo_sempre(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Checklist de organização',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'SEMPRE',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.acao_obrigatoria', true)
            ->assertJsonPath('tipo_registro.escopo_acao', 'SEMPRE')
            ->assertJsonPath('tipo_registro.campanha_auditoria_uuid', null);
    }

    public function test_escopo_campanha_sem_campanha_uuid_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
        ])->assertStatus(422)->assertJsonValidationErrors('campanha_auditoria_uuid');
    }

    public function test_acao_obrigatoria_sem_escopo_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar contrato de expositor',
            'acao_obrigatoria' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('escopo_acao');
    }

    public function test_cria_acao_vinculada_a_campanha_de_outra_empresa_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_uuid' => $campanha->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('campanha_auditoria_uuid');
    }

    public function test_cria_acao_vinculada_a_campanha_da_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_uuid' => $campanha->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('tipo_registro.campanha_auditoria_uuid', $campanha->uuid);
    }

    public function test_mudar_escopo_pra_fora_de_campanha_desvincula_a_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id,
            'descricao' => 'Auditar tabloide',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_id' => $campanha->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/tipos-registro/{$tipo->uuid}", ['escopo_acao' => 'SEMPRE']);

        $response->assertOk()->assertJsonPath('tipo_registro.campanha_auditoria_uuid', null);
        $this->assertDatabaseHas('tipos_registro', ['id' => $tipo->id, 'campanha_auditoria_id' => null]);
    }

    /**
     * Ícone — ver App\Support\IconeTipoRegistro e docs/03-ADMIN-WEB.md#tipos-de-registro. O
     * gestor digita o slug do Material Design Icons, com ou sem o prefixo "mdi-"/"mdi:" que o
     * site do MDI mostra junto do nome — os dois formatos precisam normalizar pro mesmo valor,
     * porque é ele que o mobile usa direto como `name` do MaterialCommunityIcons.
     */
    public function test_icone_com_prefixo_mdi_e_normalizado_sem_prefixo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'icone' => 'mdi-arrow-right',
        ]);

        $response->assertCreated()->assertJsonPath('tipo_registro.icone', 'arrow-right');
    }

    public function test_icone_com_prefixo_mdi_dois_pontos_tambem_e_normalizado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'icone' => 'mdi:camera',
        ]);

        $response->assertCreated()->assertJsonPath('tipo_registro.icone', 'camera');
    }

    public function test_icone_com_caractere_invalido_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'icone' => 'câmera азиатский',
        ])->assertStatus(422)->assertJsonValidationErrors('icone');
    }

    public function test_icone_e_opcional(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', ['descricao' => 'Ponto extra'])
            ->assertCreated()->assertJsonPath('tipo_registro.icone', null);
    }

    public function test_update_troca_o_icone(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra', 'icone' => 'camera']);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/tipos-registro/{$tipo->uuid}", ['icone' => 'mdi-tag']);

        $response->assertOk()->assertJsonPath('tipo_registro.icone', 'tag');
    }

    /**
     * Sequência de exibição (`ordem`) — ver TipoRegistroController::index/store/mover e
     * docs/03-ADMIN-WEB.md#tipos-de-registro. Precisa ser a mesma ordem no admin e no mobile,
     * já que os dois consomem o mesmo GET /api/tipos-registro.
     */
    public function test_listagem_ordena_por_ordem_nao_alfabetica(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        // Toda empresa nasce com os 3 tipos de fábrica (ver seedPadrao) — removidos aqui pra
        // isolar só a mecânica de ordenação sendo testada.
        TipoRegistro::where('empresa_id', $empresa->id)->delete();
        TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Zebra', 'ordem' => 0]);
        TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Abacate', 'ordem' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/tipos-registro')->assertOk();

        $this->assertSame(['Zebra', 'Abacate'], collect($response->json('tipos_registro'))->pluck('descricao')->all());
    }

    public function test_filtro_por_campanha_auditoria_uuid_lista_so_o_formulario_daquela_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $formularioDaCampanha = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Loja Perfeita', 'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA', 'campanha_auditoria_id' => $campanha->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/tipos-registro?campanha_auditoria_uuid={$campanha->uuid}")->assertOk();

        $descricoes = collect($response->json('tipos_registro'))->pluck('descricao')->all();
        $this->assertSame([$formularioDaCampanha->descricao], $descricoes);
    }

    public function test_tipo_novo_nasce_no_fim_da_lista(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        // Os 3 de fábrica já ocupam ordem 0, 1, 2 (ver TipoRegistro::seedPadrao).

        $response = $this->postJson('/api/tipos-registro', ['descricao' => 'Ponto extra']);

        $response->assertCreated()->assertJsonPath('tipo_registro.ordem', 3);
    }

    public function test_mover_para_cima_troca_com_o_anterior(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        TipoRegistro::where('empresa_id', $empresa->id)->delete();
        $primeiro = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Primeiro', 'ordem' => 0]);
        $segundo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Segundo', 'ordem' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/tipos-registro/{$segundo->uuid}/mover", ['direcao' => 'cima']);

        $response->assertOk()->assertJsonPath('tipo_registro.ordem', 0);
        $this->assertDatabaseHas('tipos_registro', ['id' => $primeiro->id, 'ordem' => 1]);
        $this->assertDatabaseHas('tipos_registro', ['id' => $segundo->id, 'ordem' => 0]);
    }

    public function test_mover_para_baixo_troca_com_o_seguinte(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        TipoRegistro::where('empresa_id', $empresa->id)->delete();
        $primeiro = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Primeiro', 'ordem' => 0]);
        $segundo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Segundo', 'ordem' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/tipos-registro/{$primeiro->uuid}/mover", ['direcao' => 'baixo']);

        $response->assertOk()->assertJsonPath('tipo_registro.ordem', 1);
        $this->assertDatabaseHas('tipos_registro', ['id' => $segundo->id, 'ordem' => 0]);
    }

    public function test_mover_para_cima_o_primeiro_da_lista_nao_faz_nada(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        TipoRegistro::where('empresa_id', $empresa->id)->delete();
        $primeiro = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Primeiro', 'ordem' => 0]);
        TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Segundo', 'ordem' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/tipos-registro/{$primeiro->uuid}/mover", ['direcao' => 'cima']);

        $response->assertOk()->assertJsonPath('tipo_registro.ordem', 0);
    }

    public function test_mover_de_outra_empresa_nao_troca_ordem_entre_empresas(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        TipoRegistro::where('empresa_id', $empresaA->id)->delete();
        TipoRegistro::where('empresa_id', $empresaB->id)->delete();
        $tipoA = TipoRegistro::create(['empresa_id' => $empresaA->id, 'descricao' => 'Só da A', 'ordem' => 5]);
        $tipoB = TipoRegistro::create(['empresa_id' => $empresaB->id, 'descricao' => 'Só da B', 'ordem' => 0]);
        Sanctum::actingAs($adminA);

        // Nenhum tipo da empresa A tem ordem menor que 5 (o global scope não enxerga a B) —
        // "mover pra cima" não deve fazer nada, muito menos mexer no tipo da outra empresa.
        $this->postJson("/api/tipos-registro/{$tipoA->uuid}/mover", ['direcao' => 'cima'])
            ->assertOk()->assertJsonPath('tipo_registro.ordem', 5);
        $this->assertDatabaseHas('tipos_registro', ['id' => $tipoB->id, 'ordem' => 0]);
    }

    public function test_mover_exige_direcao_valida(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/tipos-registro/{$tipo->uuid}/mover", ['direcao' => 'lado'])
            ->assertStatus(422)->assertJsonValidationErrors('direcao');
    }

    public function test_gestor_sem_permissao_nao_pode_mover(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        Sanctum::actingAs($gestor);

        $this->postJson("/api/tipos-registro/{$tipo->uuid}/mover", ['direcao' => 'cima'])->assertForbidden();
    }
}
