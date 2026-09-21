<?php

use App\Http\Controllers\AgendaVisitaController;
use App\Http\Controllers\AtividadeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampanhaAuditoriaController;
use App\Http\Controllers\CampanhaItemController;
use App\Http\Controllers\CampoSortimentoController;
use App\Http\Controllers\CentroCustoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\ContratoMetaController;
use App\Http\Controllers\DepartamentoAuditoriaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\GaleriaFotosController;
use App\Http\Controllers\ImagemRegistroController;
use App\Http\Controllers\ComentarioRegistroController;
use App\Http\Controllers\HistoricoLojaController;
use App\Http\Controllers\LocalizacaoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\MarcaAuditoriaController;
use App\Http\Controllers\NivelExibicaoController;
use App\Http\Controllers\ObjetivoVisitaController;
use App\Http\Controllers\DirecionamentoController;
use App\Http\Controllers\OrdemServicoController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PlanogramaBlocoController;
use App\Http\Controllers\PlanogramaController;
use App\Http\Controllers\PlanogramaPrateleiraController;
use App\Http\Controllers\PontoVendaController;
use App\Http\Controllers\ProdutoAuditoriaController;
use App\Http\Controllers\RamoAtividadeController;
use App\Http\Controllers\RedeLojaController;
use App\Http\Controllers\SecaoAuditoriaController;
use App\Http\Controllers\SortimentoPontoVendaController;
use App\Http\Controllers\TipoRegistroController;
use App\Http\Controllers\TipoVisitaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VisitaController;
use App\Http\Controllers\VisitaRegistroController;
use Illuminate\Support\Facades\Route;

// Público — signup de empresa (cria o tenant) e login.
Route::post('/empresas/signup', [EmpresaController::class, 'signup']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Autenticado (qualquer user_type).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    // Self-service de foto de perfil — qualquer autenticado troca a própria, sem exigir
    // usuarios.gerenciar (ver AuthController::atualizarFoto). A rota de leitura (servir o
    // arquivo) fica fora do grupo `permissao:usuarios.gerenciar` abaixo de propósito: o próprio
    // dono precisa poder ver a própria foto (/auth/me.foto_url aponta pra ela) mesmo sendo
    // PROMOTOR — ver UsuarioController::foto.
    Route::post('/auth/me/foto', [AuthController::class, 'atualizarFoto']);
    Route::delete('/auth/me/foto', [AuthController::class, 'removerFoto']);
    Route::get('/usuarios/{usuario}/foto', [UsuarioController::class, 'foto']);
    Route::get('/empresa', [EmpresaController::class, 'show']);

    // Catálogo de auditoria: leitura para qualquer usuário autenticado, escrita por
    // permissão — ver docs/02-API-BACKEND.md#autorização-de-escrita.
    Route::get('/departamentos-auditoria', [DepartamentoAuditoriaController::class, 'index']);
    Route::get('/secoes-auditoria', [SecaoAuditoriaController::class, 'index']);
    Route::get('/marcas-auditoria', [MarcaAuditoriaController::class, 'index']);
    Route::get('/produtos-auditoria', [ProdutoAuditoriaController::class, 'index']);
    Route::get('/niveis-exibicao', [NivelExibicaoController::class, 'index']);
    // Classificação de PontoVenda (selects no cadastro da loja, ver PontoVendaController) —
    // mesmo padrão de leitura aberta/escrita por permissão do resto do catálogo.
    Route::get('/redes-lojas', [RedeLojaController::class, 'index']);
    Route::get('/ramos-atividade', [RamoAtividadeController::class, 'index']);
    Route::get('/tipos-registro', [TipoRegistroController::class, 'index']);
    // Checklist resolvido de um campo SORTIMENTO pra um PDV — ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md
    // decisão 3. Precisa vir antes de qualquer /tipos-registro/{tipoRegistro} se um dia existir
    // (mesma nota de /campanhas-auditoria/disponiveis), mas hoje não há conflito de rota.
    Route::get('/tipos-registro/campos/{campo}/sortimento', [CampoSortimentoController::class, 'index']);
    Route::get('/tipos-registro/{tipoRegistro}', [TipoRegistroController::class, 'show']);
    Route::get('/tipos-visita', [TipoVisitaController::class, 'index']);
    Route::get('/objetivos-visita', [ObjetivoVisitaController::class, 'index']);

    // Planograma — referência visual de layout de prateleira/expositor, ver
    // docs/22-PLANOGRAMA.md. Leitura aberta, escrita por permissao:catalogo.gerenciar (bloco
    // abaixo) — dado satélite do catálogo, mesmo raciocínio de reaproveitar a permissão do
    // domínio pai em vez de criar uma nova (ver App\Enums\Permissao).
    Route::get('/planogramas', [PlanogramaController::class, 'index']);
    // /proxy-imagem precisa vir ANTES de /{planograma}, senão o Laravel tenta casar
    // "proxy-imagem" como se fosse um uuid — mesma nota de /campanhas-auditoria/disponiveis.
    // Aberta a qualquer autenticado (não exige catalogo.gerenciar) — só busca imagem, mesmo
    // raciocínio de leitura livre do index/show.
    Route::get('/planogramas/proxy-imagem', [PlanogramaController::class, 'proxyImagem']);
    Route::get('/planogramas/{planograma}', [PlanogramaController::class, 'show']);
    // Aberta a qualquer autenticado (não exige catalogo.gerenciar) — o promotor no mobile só
    // consome a capa, nunca escreve.
    Route::get('/planogramas/{planograma}/foto-capa', [PlanogramaController::class, 'fotoCapa']);

    // Campanhas de auditoria — nota de ordem: /disponiveis precisa vir ANTES de
    // /{campanhaAuditoria}, senão o Laravel tenta casar "disponiveis" como se fosse um uuid.
    Route::get('/campanhas-auditoria/disponiveis', [CampanhaAuditoriaController::class, 'disponiveis']);
    Route::get('/campanhas-auditoria', [CampanhaAuditoriaController::class, 'index']);
    Route::get('/campanhas-auditoria/{campanhaAuditoria}', [CampanhaAuditoriaController::class, 'show']);

    // Parâmetros: configuração chave/valor por empresa que o mobile baixa inteira e cacheia
    // localmente — leitura aberta a qualquer autenticado, igual ao catálogo.
    Route::get('/parametros', [ParametroController::class, 'index']);

    // Pontos de venda: leitura aberta, escrita por permissão (bloco abaixo).
    Route::get('/pontos-venda', [PontoVendaController::class, 'index']);
    Route::get('/pontos-venda/{pontoVenda}', [PontoVendaController::class, 'show']);
    // Foto da fachada — leitura aberta (mesmo padrão de UsuarioController::foto), escrita por
    // pontos_venda.gerenciar (bloco abaixo, ver PontoVendaController::atualizarFachada).
    Route::get('/pontos-venda/{pontoVenda}/fachada', [PontoVendaController::class, 'fachada']);
    // Promotor envia a fachada só quando a loja ainda não tem (não substitui a do admin).
    Route::post('/pontos-venda/{pontoVenda}/fachada-promotor', [PontoVendaController::class, 'enviarFachadaPromotor']);
    // Histórico da loja e pedidos do ERP — só leitura, qualquer autenticado (é o que o promotor vê
    // ao entrar na loja). Ver docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §4.
    Route::get('/pontos-venda/{pontoVenda}/historico', [HistoricoLojaController::class, 'show']);
    Route::get('/pontos-venda/{pontoVenda}/pedidos', [PedidoController::class, 'porPontoVenda']);
    // Escrita do integrador de ERP (§4.2.2) — idempotente por número de pedido.
    Route::middleware('permissao:pedidos.gerenciar')->group(function (): void {
        Route::post('/pedidos', [PedidoController::class, 'store']);
        Route::post('/pedidos/{pedido}/entregas', [PedidoController::class, 'entregar']);
    });

    // Visitas (check-in/checkout/registros): qualquer user_type autenticado, sem restrição de
    // permissão — ver docs/02-API-BACKEND.md#autorização-de-escrita. Ownership (um PROMOTOR
    // só mexe nas próprias visitas) é checado dentro dos controllers.
    Route::get('/visitas', [VisitaController::class, 'index']);
    Route::get('/visitas/{visita}', [VisitaController::class, 'show']);
    Route::post('/visitas', [VisitaController::class, 'store']);
    Route::patch('/visitas/{visita}/checkout', [VisitaController::class, 'checkout']);
    // Autosserviço: promotor cancela a própria visita em andamento, parametrizável por empresa
    // (VISITA_CANCELAMENTO_PERMITIDO) — mesmo raciocínio do cancelamento de registro, não se
    // confunde com a intervenção administrativa (bloco visitas.intervir abaixo). Ver
    // VisitaController::cancelarPropria.
    Route::post('/visitas/{visita}/cancelar-propria', [VisitaController::class, 'cancelarPropria']);
    // Saída de segurança: gestor/admin autoriza o cancelamento com e-mail e senha no aparelho do promotor.
    Route::post('/visitas/{visita}/cancelar-autorizado', [VisitaController::class, 'cancelarAutorizado']);
    Route::post('/visitas/{visita}/registros', [VisitaRegistroController::class, 'store']);
    // Serve o arquivo de uma foto de evidência — não depende mais de um registro específico
    // (uma foto pode evidenciar N registros), ver docs/21-EVIDENCIA-EM-FOTOS.md.
    Route::get('/visitas/{visita}/imagens/{imagem}', [ImagemRegistroController::class, 'show']);
    // Cancelamento (soft) de um registro já feito — parametrizável por empresa pra PROMOTOR
    // (REGISTRO_CANCELAMENTO_PERMITIDO), ADMIN/GESTOR sempre podem. Ver
    // VisitaRegistroController::cancelar.
    Route::post('/visitas/{visita}/registros/{registro}/cancelar', [VisitaRegistroController::class, 'cancelar']);
    // Painel de Atividades: ADMIN/GESTOR marca um alerta como resolvido — ver
    // VisitaRegistroController::resolverAlerta e docs/17-PAINEL-ATIVIDADES.md.
    // Feedback em registro (docs/28 §3): feed de comentários promotor <-> admin/gestor + badge de
    // não lidos por polling. Ownership igual ao do registro, checado no controller.
    Route::get('/comentarios/nao-lidos', [ComentarioRegistroController::class, 'naoLidos']);
    Route::get('/visitas/{visita}/registros/{registro}/comentarios', [ComentarioRegistroController::class, 'index']);
    Route::post('/visitas/{visita}/registros/{registro}/comentarios', [ComentarioRegistroController::class, 'store']);
    Route::post('/visitas/{visita}/registros/{registro}/resolver-alerta', [VisitaRegistroController::class, 'resolverAlerta']);

    // Painel de Atividades: feed agregado (check-in/checkout/alertas) pra ADMIN/GESTOR
    // acompanhar tudo que rolou nas visitas do dia, todo mundo junto — ver
    // AtividadeController::index e docs/17-PAINEL-ATIVIDADES.md.
    Route::get('/atividades', [AtividadeController::class, 'index']);

    // Galeria de Fotos: grade só de fotos (não timeline), filtrável por período/tipo de
    // registro/catálogo/loja/rede/ramo/promotor/ruptura — ver GaleriaFotosController::index e
    // docs/23-GALERIA-DE-FOTOS.md.
    Route::get('/galeria-fotos', [GaleriaFotosController::class, 'index']);

    // Relatórios agregados (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §2) — ADMIN/GESTOR, checado
    // no controller (mesmo padrão do Painel de Atividades).
    Route::get('/relatorios/visitas-planejadas-x-executadas', [RelatorioController::class, 'visitasPlanejadasXExecutadas']);
    Route::get('/relatorios/respostas-formulario', [RelatorioController::class, 'respostasFormulario']);
    Route::get('/relatorios/visitas-planejadas-x-executadas/pdf', [RelatorioController::class, 'visitasPlanejadasXExecutadasPdf']);
    Route::get('/relatorios/respostas-formulario/pdf', [RelatorioController::class, 'respostasFormularioPdf']);

    // Rastreamento em tempo real (docs/11-RASTREAMENTO-TEMPO-REAL.md): o promotor manda a própria
    // posição; o mapa ao vivo do admin lê a lista, sob permissão dedicada.
    Route::patch('/localizacao', [LocalizacaoController::class, 'atualizar']);
    Route::get('/localizacoes', [LocalizacaoController::class, 'index'])->middleware('permissao:rastreamento.visualizar');

    // Intervenção administrativa em visita (cancelar / forçar checkout com horário real /
    // corrigir horários) — exige a permissão dedicada visitas.intervir (ADMIN sempre; GESTOR só
    // com ela no perfil; PROMOTOR nunca). Toda ação exige motivo e grava log de auditoria. Ver
    // docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
    Route::middleware('permissao:visitas.intervir')->group(function (): void {
        Route::post('/visitas/{visita}/cancelar', [VisitaController::class, 'cancelar']);
        Route::post('/visitas/{visita}/forcar-checkout', [VisitaController::class, 'forcarCheckout']);
        Route::patch('/visitas/{visita}/horarios', [VisitaController::class, 'corrigirHorarios']);
    });

    // Ordens de serviço (compromisso de visita direcionado pelo gestor) — leitura aberta a
    // qualquer autenticado, com ownership igual a visitas (PROMOTOR só vê as suas + fila
    // aberta); escrita por permissão (bloco abaixo). Ver docs/07-ORDEM-DE-SERVICO.md.
    Route::get('/ordens-servico', [OrdemServicoController::class, 'index']);
    // Busca individual — o mobile usa isso pra ler os formulários pendentes da OS vinculada à
    // visita, ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §6.
    Route::get('/ordens-servico/{ordemServico}', [OrdemServicoController::class, 'show']);

    // Self-service: o próprio promotor cria/reagenda/cancela a própria OS ("+ Compromisso" no
    // mobile) — ownership em vez de RBAC, sem exigir ordens_servico.gerenciar. Autônomo ou vira
    // solicitação, dependendo do parâmetro AGENDA_REQUER_APROVACAO da empresa. Ver
    // docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
    Route::post('/ordens-servico/minhas', [OrdemServicoController::class, 'minhas']);
    Route::post('/ordens-servico/{ordemServico}/reagendar', [OrdemServicoController::class, 'reagendar']);
    Route::post('/ordens-servico/{ordemServico}/cancelar', [OrdemServicoController::class, 'cancelar']);

    // Self-service: o promotor cresce o sortimento do PDV ou cadastra um produto novo pela
    // própria visita — autonomia parametrizável por empresa (SORTIMENTO_AUTONOMIA_PROMOTOR/
    // CATALOGO_AUTONOMIA_PROMOTOR), sem exigir pontos_venda.gerenciar/catalogo.gerenciar. Ver
    // docs/14-SORTIMENTO-PONTO-VENDA.md §9.
    Route::post('/pontos-venda/{pontoVenda}/sortimento/proprio', [SortimentoPontoVendaController::class, 'adicionarProprio']);
    Route::post('/produtos-auditoria/proprio', [ProdutoAuditoriaController::class, 'proprio']);

    // Gestão de perfis (RBAC por empresa) — sempre ADMIN, nunca delegável por permissão
    // (evita um GESTOR se auto-promover editando o próprio perfil). Ver App\Models\Perfil.
    Route::middleware('user_type:ADMIN')->group(function (): void {
        Route::put('/empresa', [EmpresaController::class, 'update']);

        Route::get('/perfis', [PerfilController::class, 'index']);
        Route::post('/perfis', [PerfilController::class, 'store']);
        Route::put('/perfis/{perfil}', [PerfilController::class, 'update']);
        Route::delete('/perfis/{perfil}', [PerfilController::class, 'destroy']);
    });

    // Suporte (SUPERADMIN) — fora do multi-tenancy. CRUD de empresas clientes (planos, limites,
    // bloqueio) + a mesma lista alimenta o filtro de empresa na busca de usuários (ver
    // EnsurePermissao e UsuarioController::index).
    Route::middleware('user_type:SUPERADMIN')->group(function (): void {
        Route::get('/superadmin/empresas', [EmpresaController::class, 'indexSuperadmin']);
        Route::post('/superadmin/empresas', [EmpresaController::class, 'storeSuperadmin']);
        Route::get('/superadmin/empresas/{empresa}', [EmpresaController::class, 'showSuperadmin']);
        Route::put('/superadmin/empresas/{empresa}', [EmpresaController::class, 'updateSuperadmin']);
        Route::delete('/superadmin/empresas/{empresa}', [EmpresaController::class, 'destroySuperadmin']);

        // Faturas: registro manual de cobrança por empresa — ver App\Models\Fatura.
        Route::get('/superadmin/empresas/{empresa}/faturas', [FaturaController::class, 'index']);
        Route::post('/superadmin/empresas/{empresa}/faturas', [FaturaController::class, 'store']);
        Route::put('/superadmin/empresas/{empresa}/faturas/{fatura}', [FaturaController::class, 'update']);
    });

    // Escrita nos cadastros — ADMIN sempre libera; GESTOR depende da permissão no perfil
    // atribuído a ele. Ver App\Http\Middleware\EnsurePermissao.
    Route::post('/pontos-venda', [PontoVendaController::class, 'store'])->middleware('permissao:pontos_venda.gerenciar');
    Route::put('/pontos-venda/{pontoVenda}', [PontoVendaController::class, 'update'])->middleware('permissao:pontos_venda.gerenciar');
    Route::delete('/pontos-venda/{pontoVenda}', [PontoVendaController::class, 'destroy'])->middleware('permissao:pontos_venda.gerenciar');
    // Foto da fachada da loja — mesmo padrão de AuthController::atualizarFoto/removerFoto, só
    // que aqui não é self-service (é a loja, não o usuário), por isso exige a permissão.
    Route::post('/pontos-venda/{pontoVenda}/fachada', [PontoVendaController::class, 'atualizarFachada'])->middleware('permissao:pontos_venda.gerenciar');
    Route::delete('/pontos-venda/{pontoVenda}/fachada', [PontoVendaController::class, 'removerFachada'])->middleware('permissao:pontos_venda.gerenciar');
    // Atribuição de promotores à loja (regra de negócio 6) — mesma permissão de gerenciar PDV.
    Route::put('/pontos-venda/{pontoVenda}/promotores', [PontoVendaController::class, 'syncPromotores'])->middleware('permissao:pontos_venda.gerenciar');
    // Atribuir/remover um promotor de cada vez — preferível ao sync acima quando só uma
    // atribuição muda por vez (evita a race de recalcular a lista inteira no cliente).
    Route::post('/pontos-venda/{pontoVenda}/promotores/{usuario}', [PontoVendaController::class, 'attachPromotor'])->middleware('permissao:pontos_venda.gerenciar');
    Route::delete('/pontos-venda/{pontoVenda}/promotores/{usuario}', [PontoVendaController::class, 'detachPromotor'])->middleware('permissao:pontos_venda.gerenciar');

    // Sortimento por PDV — cadastro pelo admin web sempre nasce válido; aprovar/rejeitar decide
    // um item PENDENTE criado por um promotor pela visita. Ver docs/14-SORTIMENTO-PONTO-VENDA.md.
    Route::middleware('permissao:pontos_venda.gerenciar')->group(function (): void {
        Route::post('/pontos-venda/{pontoVenda}/sortimento', [SortimentoPontoVendaController::class, 'store']);
        Route::delete('/pontos-venda/{pontoVenda}/sortimento/{item}', [SortimentoPontoVendaController::class, 'destroy']);
        Route::post('/pontos-venda/{pontoVenda}/sortimento/{item}/aprovar', [SortimentoPontoVendaController::class, 'aprovar']);
        Route::post('/pontos-venda/{pontoVenda}/sortimento/{item}/rejeitar', [SortimentoPontoVendaController::class, 'rejeitar']);
    });

    Route::middleware('permissao:catalogo.gerenciar')->group(function (): void {
        Route::post('/departamentos-auditoria', [DepartamentoAuditoriaController::class, 'store']);
        Route::put('/departamentos-auditoria/{departamentoAuditoria}', [DepartamentoAuditoriaController::class, 'update']);
        Route::delete('/departamentos-auditoria/{departamentoAuditoria}', [DepartamentoAuditoriaController::class, 'destroy']);

        Route::post('/secoes-auditoria', [SecaoAuditoriaController::class, 'store']);
        Route::put('/secoes-auditoria/{secaoAuditoria}', [SecaoAuditoriaController::class, 'update']);
        Route::delete('/secoes-auditoria/{secaoAuditoria}', [SecaoAuditoriaController::class, 'destroy']);

        Route::post('/marcas-auditoria', [MarcaAuditoriaController::class, 'store']);
        Route::put('/marcas-auditoria/{marcaAuditoria}', [MarcaAuditoriaController::class, 'update']);
        Route::delete('/marcas-auditoria/{marcaAuditoria}', [MarcaAuditoriaController::class, 'destroy']);

        Route::post('/produtos-auditoria', [ProdutoAuditoriaController::class, 'store']);
        Route::put('/produtos-auditoria/{produtoAuditoria}', [ProdutoAuditoriaController::class, 'update']);
        Route::delete('/produtos-auditoria/{produtoAuditoria}', [ProdutoAuditoriaController::class, 'destroy']);
        // Painel de aprovação de produto cadastrado por promotor (self-service) — ver
        // docs/14-SORTIMENTO-PONTO-VENDA.md §9.
        Route::post('/produtos-auditoria/{produtoAuditoria}/aprovar', [ProdutoAuditoriaController::class, 'aprovar']);
        Route::post('/produtos-auditoria/{produtoAuditoria}/rejeitar', [ProdutoAuditoriaController::class, 'rejeitar']);

        Route::post('/niveis-exibicao', [NivelExibicaoController::class, 'store']);
        Route::put('/niveis-exibicao/{nivelExibicao}', [NivelExibicaoController::class, 'update']);
        Route::delete('/niveis-exibicao/{nivelExibicao}', [NivelExibicaoController::class, 'destroy']);

        Route::post('/redes-lojas', [RedeLojaController::class, 'store']);
        Route::put('/redes-lojas/{redeLoja}', [RedeLojaController::class, 'update']);
        Route::delete('/redes-lojas/{redeLoja}', [RedeLojaController::class, 'destroy']);

        Route::post('/ramos-atividade', [RamoAtividadeController::class, 'store']);
        Route::put('/ramos-atividade/{ramoAtividade}', [RamoAtividadeController::class, 'update']);
        Route::delete('/ramos-atividade/{ramoAtividade}', [RamoAtividadeController::class, 'destroy']);

        Route::post('/tipos-registro', [TipoRegistroController::class, 'store']);
        Route::put('/tipos-registro/{tipoRegistro}', [TipoRegistroController::class, 'update']);
        Route::delete('/tipos-registro/{tipoRegistro}', [TipoRegistroController::class, 'destroy']);
        // Sequência de exibição (admin e mobile, ver TipoRegistroController::index) — troca a
        // `ordem` deste tipo com a do vizinho (anterior/seguinte), em vez de expor o número cru.
        Route::post('/tipos-registro/{tipoRegistro}/mover', [TipoRegistroController::class, 'mover']);
        // Duplicar (decisão 6 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — clona um tipo
        // existente (com todos os campos) como ponto de partida de um formulário novo.
        Route::post('/tipos-registro/{tipoRegistro}/duplicar', [TipoRegistroController::class, 'duplicar']);

        Route::post('/planogramas', [PlanogramaController::class, 'store']);
        Route::put('/planogramas/{planograma}', [PlanogramaController::class, 'update']);
        Route::delete('/planogramas/{planograma}', [PlanogramaController::class, 'destroy']);
        Route::post('/planogramas/{planograma}/foto-capa', [PlanogramaController::class, 'uploadFotoCapa']);

        Route::post('/planogramas/{planograma}/prateleiras', [PlanogramaPrateleiraController::class, 'store']);
        Route::put('/planogramas/{planograma}/prateleiras/{prateleira}', [PlanogramaPrateleiraController::class, 'update']);
        Route::delete('/planogramas/{planograma}/prateleiras/{prateleira}', [PlanogramaPrateleiraController::class, 'destroy']);

        Route::post('/planogramas/{planograma}/prateleiras/{prateleira}/blocos', [PlanogramaBlocoController::class, 'store']);
        Route::put('/planogramas/{planograma}/prateleiras/{prateleira}/blocos/{bloco}', [PlanogramaBlocoController::class, 'update']);
        Route::delete('/planogramas/{planograma}/prateleiras/{prateleira}/blocos/{bloco}', [PlanogramaBlocoController::class, 'destroy']);
    });

    // Campanhas: permissão própria, separada do catálogo — definir "o que auditar" é uma
    // responsabilidade diferente de manter departamentos/seções/marcas/produtos.
    Route::middleware('permissao:campanhas.gerenciar')->group(function (): void {
        Route::post('/campanhas-auditoria', [CampanhaAuditoriaController::class, 'store']);
        Route::put('/campanhas-auditoria/{campanhaAuditoria}', [CampanhaAuditoriaController::class, 'update']);
        Route::delete('/campanhas-auditoria/{campanhaAuditoria}', [CampanhaAuditoriaController::class, 'destroy']);

        Route::post('/campanhas-auditoria/{campanhaAuditoria}/itens', [CampanhaItemController::class, 'store']);
        Route::delete('/campanhas-auditoria/{campanhaAuditoria}/itens/{item}', [CampanhaItemController::class, 'destroy']);
    });

    Route::post('/parametros', [ParametroController::class, 'store'])->middleware('permissao:parametros.gerenciar');
    Route::put('/parametros/{parametro}', [ParametroController::class, 'update'])->middleware('permissao:parametros.gerenciar');
    Route::delete('/parametros/{parametro}', [ParametroController::class, 'destroy'])->middleware('permissao:parametros.gerenciar');

    // Gestão de usuários (promotores/gestores/admins da empresa) — tudo por permissão,
    // inclusive a leitura (lista nome/e-mail/user_type de colegas, não é dado de catálogo).
    // SUPERADMIN nunca é atribuível aqui, só via comando artisan.
    Route::middleware('permissao:usuarios.gerenciar')->group(function (): void {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::get('/usuarios/{usuario}', [UsuarioController::class, 'show']);
        Route::get('/usuarios/{usuario}/historico', [UsuarioController::class, 'historico']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update']);
        Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'destroy']);
        // Força logout do dispositivo atual (perda/roubo de aparelho, troca de promotor) —
        // ver App\Http\Controllers\UsuarioController::revogarDispositivo. Nunca pelo
        // SUPERADMIN (DELETE continua bloqueado pra ele, ver EnsurePermissao).
        Route::delete('/usuarios/{usuario}/dispositivo', [UsuarioController::class, 'revogarDispositivo']);
    });

    // Contratos (comodato de expositor, ponto extra) entre a empresa e um PDV — ver
    // docs/02-API-BACKEND.md. SUPERADMIN cadastra/edita pra qualquer empresa (suporte), igual
    // usuarios.gerenciar acima; ADMIN/GESTOR só a própria (BelongsToEmpresa).
    Route::middleware('permissao:contratos.gerenciar')->group(function (): void {
        Route::get('/contratos', [ContratoController::class, 'index']);
        // Página de detalhe do admin web (dados + metas + anexo + histórico numa tela só, ver
        // docs/03-ADMIN-WEB.md#6-contratos) — a listagem sozinha (com ?with_metas=1) não
        // bastava mais, precisa buscar um contrato específico direto (ex.: acesso por link).
        Route::get('/contratos/{contrato}', [ContratoController::class, 'show']);
        Route::post('/contratos', [ContratoController::class, 'store']);
        Route::put('/contratos/{contrato}', [ContratoController::class, 'update']);
        Route::delete('/contratos/{contrato}', [ContratoController::class, 'destroy']);
        Route::post('/contratos/{contrato}/arquivo', [ContratoController::class, 'uploadArquivo']);
        Route::get('/contratos/{contrato}/arquivo', [ContratoController::class, 'arquivo']);
        Route::get('/contratos/{contrato}/historico', [ContratoController::class, 'historico']);

        // Metas de contrapartida comercial (verba de trade marketing) — sub-recurso de
        // Contrato, mesma permissão, mesmo padrão de rotas aninhadas de campanhas-auditoria/itens.
        // Ver docs/09-CONTRATO-METAS.md.
        Route::post('/contratos/{contrato}/metas', [ContratoMetaController::class, 'store']);
        Route::put('/contratos/{contrato}/metas/{meta}', [ContratoMetaController::class, 'update']);
        Route::delete('/contratos/{contrato}/metas/{meta}', [ContratoMetaController::class, 'destroy']);
    });

    Route::middleware('permissao:ordens_servico.gerenciar')->group(function (): void {
        Route::post('/ordens-servico', [OrdemServicoController::class, 'store']);
        Route::put('/ordens-servico/{ordemServico}', [OrdemServicoController::class, 'update']);
        // Cancelamento em lote, independente de Direcionamento — ver docs/25 §2 decisão 7.
        Route::post('/ordens-servico/cancelar-em-lote', [OrdemServicoController::class, 'cancelarEmLote']);

        // Direcionamento (molde que gera Ordem de Serviço em massa) — leitura e escrita atrás da
        // mesma permissão, diferente de /ordens-servico (index é aberto): não há caso de uso pro
        // promotor ler um Direcionamento direto, só as OS que ele gerou. Ver
        // docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md.
        Route::get('/direcionamentos', [DirecionamentoController::class, 'index']);
        Route::get('/direcionamentos/{direcionamento}', [DirecionamentoController::class, 'show']);
        Route::post('/direcionamentos', [DirecionamentoController::class, 'store']);
        Route::put('/direcionamentos/{direcionamento}', [DirecionamentoController::class, 'update']);
        // Painel de aprovação (docs/13-AGENDA-MOBILE-E-AUTONOMIA.md §6.2) — decide uma
        // solicitação pendente (AGUARDANDO_APROVACAO/REAGENDAMENTO_SOLICITADO/
        // CANCELAMENTO_SOLICITADO), criada por um promotor quando a empresa exige aprovação.
        Route::post('/ordens-servico/{ordemServico}/aprovar', [OrdemServicoController::class, 'aprovar']);
        Route::post('/ordens-servico/{ordemServico}/rejeitar', [OrdemServicoController::class, 'rejeitar']);

        // Tipo de visita (tag colorida), objetivo de visita (motivo de negócio) e agenda de
        // visita (regra recorrente/pontual que gera OS automaticamente) — dado satélite de OS,
        // mesma permissão. Ver docs/10-AGENDA-VISITA.md e docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
        Route::post('/tipos-visita', [TipoVisitaController::class, 'store']);
        Route::put('/tipos-visita/{tipoVisita}', [TipoVisitaController::class, 'update']);
        Route::delete('/tipos-visita/{tipoVisita}', [TipoVisitaController::class, 'destroy']);

        Route::post('/objetivos-visita', [ObjetivoVisitaController::class, 'store']);
        Route::put('/objetivos-visita/{objetivoVisita}', [ObjetivoVisitaController::class, 'update']);
        Route::delete('/objetivos-visita/{objetivoVisita}', [ObjetivoVisitaController::class, 'destroy']);

        Route::get('/agendas-visita', [AgendaVisitaController::class, 'index']);
        Route::get('/agendas-visita/relatorio-rota', [AgendaVisitaController::class, 'relatorioRota']);
        Route::post('/agendas-visita', [AgendaVisitaController::class, 'store']);
        Route::put('/agendas-visita/{agendaVisita}', [AgendaVisitaController::class, 'update']);
        Route::delete('/agendas-visita/{agendaVisita}', [AgendaVisitaController::class, 'destroy']);
    });

    // Centros de custo: dado financeiro, diferente do resto do catálogo a LEITURA também exige
    // a permissão (nunca aberta a qualquer autenticado) — ver docs/08-CENTRO-DE-CUSTO.md.
    Route::middleware('permissao:centros_custo.gerenciar')->group(function (): void {
        Route::get('/centros-custo', [CentroCustoController::class, 'index']);
        Route::post('/centros-custo', [CentroCustoController::class, 'store']);
        Route::put('/centros-custo/{centroCusto}', [CentroCustoController::class, 'update']);
        Route::delete('/centros-custo/{centroCusto}', [CentroCustoController::class, 'destroy']);
    });
});
