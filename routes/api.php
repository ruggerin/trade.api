<?php

use App\Http\Controllers\AgendaVisitaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampanhaAuditoriaController;
use App\Http\Controllers\CampanhaItemController;
use App\Http\Controllers\CentroCustoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\ContratoMetaController;
use App\Http\Controllers\DepartamentoAuditoriaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\MarcaAuditoriaController;
use App\Http\Controllers\NivelExibicaoController;
use App\Http\Controllers\ObjetivoVisitaController;
use App\Http\Controllers\OrdemServicoController;
use App\Http\Controllers\ParametroController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PontoVendaController;
use App\Http\Controllers\ProdutoAuditoriaController;
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
    Route::get('/empresa', [EmpresaController::class, 'show']);

    // Catálogo de auditoria: leitura para qualquer usuário autenticado, escrita por
    // permissão — ver docs/02-API-BACKEND.md#autorização-de-escrita.
    Route::get('/departamentos-auditoria', [DepartamentoAuditoriaController::class, 'index']);
    Route::get('/secoes-auditoria', [SecaoAuditoriaController::class, 'index']);
    Route::get('/marcas-auditoria', [MarcaAuditoriaController::class, 'index']);
    Route::get('/produtos-auditoria', [ProdutoAuditoriaController::class, 'index']);
    Route::get('/niveis-exibicao', [NivelExibicaoController::class, 'index']);
    Route::get('/tipos-registro', [TipoRegistroController::class, 'index']);
    Route::get('/tipos-visita', [TipoVisitaController::class, 'index']);
    Route::get('/objetivos-visita', [ObjetivoVisitaController::class, 'index']);

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

    // Visitas (check-in/checkout/registros): qualquer user_type autenticado, sem restrição de
    // permissão — ver docs/02-API-BACKEND.md#autorização-de-escrita. Ownership (um PROMOTOR
    // só mexe nas próprias visitas) é checado dentro dos controllers.
    Route::get('/visitas', [VisitaController::class, 'index']);
    Route::get('/visitas/{visita}', [VisitaController::class, 'show']);
    Route::post('/visitas', [VisitaController::class, 'store']);
    Route::patch('/visitas/{visita}/checkout', [VisitaController::class, 'checkout']);
    Route::post('/visitas/{visita}/registros', [VisitaRegistroController::class, 'store']);
    Route::get('/visitas/{visita}/registros/{registro}/imagem', [VisitaRegistroController::class, 'imagem']);
    // Cancelamento (soft) de um registro já feito — parametrizável por empresa pra PROMOTOR
    // (REGISTRO_CANCELAMENTO_PERMITIDO), ADMIN/GESTOR sempre podem. Ver
    // VisitaRegistroController::cancelar.
    Route::post('/visitas/{visita}/registros/{registro}/cancelar', [VisitaRegistroController::class, 'cancelar']);

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

        Route::post('/tipos-registro', [TipoRegistroController::class, 'store']);
        Route::put('/tipos-registro/{tipoRegistro}', [TipoRegistroController::class, 'update']);
        Route::delete('/tipos-registro/{tipoRegistro}', [TipoRegistroController::class, 'destroy']);
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
