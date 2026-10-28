@php($base = url('/api/integracao/v1'))
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API de Integração — Horus</title>
<meta name="description" content="Como o ERP e o BI do cliente leem e enviam dados para o Horus.">
<style>
  :root { --bg:#fff; --fg:#1d1b2e; --muted:#5f5c74; --line:#e4e2ee; --code:#f5f4fa; --accent:#5b4bdb; --ok:#15803d; --warn:#b45309; }
  @media (prefers-color-scheme: dark) { :root { --bg:#14131d; --fg:#e8e6f3; --muted:#a4a1b8; --line:#2c2a3b; --code:#1d1c29; --accent:#a99cff; --ok:#4ade80; --warn:#fbbf24; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  .wrap { display:grid; grid-template-columns:240px minmax(0,1fr); max-width:1180px; margin:0 auto; }
  nav { position:sticky; top:0; align-self:start; height:100vh; overflow-y:auto; padding:28px 16px 28px 20px; border-right:1px solid var(--line); font-size:14px; }
  nav b { display:block; margin:18px 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
  nav a { display:block; padding:3px 0; color:var(--fg); text-decoration:none; }
  nav a:hover { color:var(--accent); }
  main { padding:32px 40px 80px; min-width:0; }
  h1 { font-size:30px; margin:0 0 6px; }
  h2 { font-size:22px; margin:48px 0 12px; padding-top:12px; border-top:1px solid var(--line); }
  h3 { font-size:17px; margin:28px 0 8px; }
  a { color:var(--accent); }
  p, li { color:var(--fg); }
  .sub { color:var(--muted); margin:0 0 20px; }
  code { font-family:ui-monospace,"IBM Plex Mono",Consolas,monospace; font-size:13px; background:var(--code); padding:1px 5px; border-radius:4px; }
  pre { background:var(--code); border:1px solid var(--line); border-radius:8px; padding:14px 16px; overflow-x:auto; font-size:13px; line-height:1.5; }
  pre code { background:none; padding:0; }
  table { width:100%; border-collapse:collapse; margin:10px 0 16px; font-size:14px; display:block; overflow-x:auto; }
  th, td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { color:var(--muted); font-weight:600; font-size:13px; white-space:nowrap; }
  td code { white-space:nowrap; }
  .metodo { display:inline-block; min-width:40px; font:600 12px ui-monospace,monospace; color:#fff; border-radius:4px; padding:1px 6px; text-align:center; }
  .get { background:#0e7490; } .put { background:#7c3aed; }
  .nota { border-left:3px solid var(--accent); background:var(--code); padding:10px 14px; border-radius:0 6px 6px 0; margin:14px 0; }
  .aviso { border-left-color:var(--warn); }
  @media (max-width: 860px) { .wrap { grid-template-columns:1fr; } nav { display:none; } main { padding:20px 16px 60px; } }
</style>
</head>
<body>
<div class="wrap">
<nav>
  <b>Começo</b>
  <a href="#visao">Visão geral</a>
  <a href="#token">Token e permissões</a>
  <a href="#convencoes">Convenções</a>
  <a href="#erros">Erros</a>
  <a href="#limites">Limites</a>
  <b>Leitura (BI)</b>
  <a href="#leitura">Como ler</a>
  <a href="#rotas-leitura">Rotas de leitura</a>
  <a href="#fotos">Fotos</a>
  <b>Escrita (ERP)</b>
  <a href="#escrita">Como enviar</a>
  <a href="#ordem">Ordem da carga inicial</a>
  <a href="#rotas-escrita">Rotas e campos</a>
  <a href="#pedidos">Pedidos e entregas</a>
  <a href="#idempotencia">Reenvio seguro</a>
  <b>Avisos</b>
  <a href="#webhooks">Webhooks</a>
  <a href="#assinatura">Conferir a assinatura</a>
</nav>
<main>
<h1>API de Integração</h1>
<p class="sub">Para o ERP enviar cadastros e pedidos ao Horus, e para o BI ler visitas, registros, rupturas e XP. Versão <code>v1</code>.</p>

<h2 id="visao">Visão geral</h2>
<p>Endereço base de todas as rotas:</p>
<pre><code>{{ $base }}</code></pre>
<ul>
  <li><b>Leitura</b> (<code>GET</code>): listas paginadas com leitura incremental, para o BI copiar só o que mudou.</li>
  <li><b>Escrita</b> (<code>PUT</code>): envio em lote pelo código do ERP (<code>codigo_externo</code>). O que não existe é criado, o que existe é atualizado.</li>
  <li><b>Webhooks</b>: o Horus avisa o seu sistema quando uma visita termina ou uma ruptura é registrada.</li>
</ul>
<p>A <code>v1</code> não quebra: campos novos podem aparecer nas respostas, mas um campo existente não muda de nome nem some. Ignore campos que você não conhece.</p>

<h2 id="token">Token e permissões</h2>
<p>Um administrador da empresa cria a integração em <b>Integrações</b>, no painel do Horus, e marca o que ela pode acessar. O token aparece uma única vez, na criação: guarde em lugar seguro.</p>
<pre><code>Authorization: Bearer horus_live_xxxxxxxxxxxxxxxxxxxxxxxx</code></pre>
<p>Cada rota exige uma permissão. <b>Escrever não dá direito a ler</b>: <code>lojas:escrever</code> grava lojas, mas não as lê de volta.</p>
<table>
  <tr><th>Permissão</th><th>Dá acesso a</th></tr>
  <tr><td><code>visitas:ler</code></td><td>Visitas: horários, check-in/out, afastamento</td></tr>
  <tr><td><code>registros:ler</code></td><td>Registros feitos nas visitas e as fotos</td></tr>
  <tr><td><code>rupturas:ler</code></td><td>Rupturas e a resolução</td></tr>
  <tr><td><code>ordens_servico:ler</code></td><td>Agenda prevista × realizada</td></tr>
  <tr><td><code>xp:ler</code></td><td>Lançamentos de XP por promotor e visita</td></tr>
  <tr><td><code>lojas:ler</code> / <code>lojas:escrever</code></td><td>Lojas (PDVs). Escrever inclui redes e ramos de atividade</td></tr>
  <tr><td><code>produtos:ler</code> / <code>produtos:escrever</code></td><td>Produtos. Escrever inclui marcas, departamentos e seções</td></tr>
  <tr><td><code>mix:ler</code> / <code>mix:escrever</code></td><td>Mix (sortimento) de cada loja</td></tr>
  <tr><td><code>vendedores:ler</code> / <code>vendedores:escrever</code></td><td>Vendedores, supervisores e a carteira vendedor × loja</td></tr>
  <tr><td><code>usuarios:ler</code> / <code>usuarios:escrever</code></td><td>Promotores. Ler traz só nome e código (nunca CPF, e-mail ou telefone)</td></tr>
  <tr><td><code>pedidos:ler</code> / <code>pedidos:escrever</code></td><td>Pedidos do ERP, faturamento e entregas</td></tr>
</table>
<p>A integração também pode ter lista de IPs permitidos, validade do token e ser pausada a qualquer momento pelo administrador.</p>

<h2 id="convencoes">Convenções</h2>
<ul>
  <li>JSON em UTF-8, com <code>Content-Type: application/json</code> nas escritas.</li>
  <li>Estrutura em inglês (<code>data</code>, <code>next_cursor</code>, <code>errors</code>…); campos de dado em português, iguais às planilhas de importação.</li>
  <li>Datas e horários em ISO 8601 UTC (<code>2026-10-22T13:05:00Z</code>). O fuso da empresa vem em <code>timezone</code>, para você converter.</li>
  <li>Registros são identificados por <code>uuid</code>; cadastros vindos do ERP também por <code>codigo_externo</code>. O id interno nunca é exposto.</li>
  <li>Cada token só enxerga os dados da própria empresa.</li>
</ul>

<h2 id="erros">Erros</h2>
<p>Todo erro segue o formato <i>problem details</i> (RFC 9457), com <code>Content-Type: application/problem+json</code>. O texto vem em português.</p>
@verbatim
<pre><code>{
  "type": "https://…/docs/integracao#erros",
  "title": "Dados inválidos",
  "status": 422,
  "detail": "Confira os campos em `errors`.",
  "errors": { "limit": ["O campo limit não pode ser superior a 1000."] }
}</code></pre>
@endverbatim
<table>
  <tr><th>Status</th><th>Quando</th></tr>
  <tr><td><code>400</code></td><td>Requisição malformada (ex.: <code>Idempotency-Key</code> inválida)</td></tr>
  <tr><td><code>401</code></td><td>Token ausente, inválido, vencido ou revogado</td></tr>
  <tr><td><code>403</code></td><td>Sem a permissão da rota, integração pausada, empresa bloqueada ou IP fora da lista</td></tr>
  <tr><td><code>404</code></td><td>Rota ou registro não encontrado</td></tr>
  <tr><td><code>409</code></td><td>A mesma <code>Idempotency-Key</code> ainda está em processamento: tente em instantes</td></tr>
  <tr><td><code>422</code></td><td>Parâmetro ou corpo inválido; detalhes em <code>errors</code></td></tr>
  <tr><td><code>429</code></td><td>Limite por minuto atingido: espere os segundos de <code>Retry-After</code></td></tr>
  <tr><td><code>500</code></td><td>Erro nosso. O <code>detail</code> traz um código para informar ao suporte</td></tr>
</table>

<h2 id="limites">Limites</h2>
<ul>
  <li>Requisições por minuto: definido por integração (padrão 120). Toda resposta traz <code>X-RateLimit-Limit</code> e <code>X-RateLimit-Remaining</code>.</li>
  <li>Leitura: até 1000 registros por página (padrão 500).</li>
  <li>Escrita: até 500 itens por chamada; mix e carteira até 50.000; pedidos até 100 por chamada (cada um com até 500 itens); entregas até 500.</li>
</ul>

<h2 id="leitura">Como ler</h2>
<p>Toda lista responde no mesmo formato:</p>
@verbatim
<pre><code>{
  "data": [ { "uuid": "…", "alterado_em": "2026-10-22T13:05:00Z", … } ],
  "next_cursor": "eyJpZCI6…",
  "timezone": "America/Manaus"
}</code></pre>
@endverbatim
<table>
  <tr><th>Parâmetro</th><th>Uso</th></tr>
  <tr><td><code>limit</code></td><td>Registros por página, 1 a 1000 (padrão 500)</td></tr>
  <tr><td><code>cursor</code></td><td>O <code>next_cursor</code> da página anterior. <code>null</code> = acabou</td></tr>
  <tr><td><code>updated_since</code></td><td>Só o que mudou a partir deste instante (ISO 8601)</td></tr>
  <tr><td><code>from</code> / <code>to</code></td><td>Período pela data principal do recurso (<code>AAAA-MM-DD</code>, inclusivo)</td></tr>
  <tr><td><code>loja</code> / <code>promotor</code></td><td>Filtra por uuid da loja ou do promotor, onde fizer sentido</td></tr>
</table>
<div class="nota"><b>Leitura incremental:</b> as listas vêm em ordem de alteração. Guarde o maior <code>alterado_em</code> recebido e, na próxima sincronização, peça <code>?updated_since=</code> esse valor. Percorra as páginas até <code>next_cursor</code> vir <code>null</code>.</div>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  "{{ $base }}/visitas?updated_since=2026-10-22T00:00:00Z&amp;limit=500"</code></pre>

<h3 id="rotas-leitura">Rotas de leitura</h3>
<table>
  <tr><th>Rota</th><th>Permissão</th><th>Data de <code>from</code>/<code>to</code></th><th>O que traz</th></tr>
  <tr><td><span class="metodo get">GET</span> <code>/visitas</code></td><td><code>visitas:ler</code></td><td>início</td><td>uuid, loja_uuid, promotor_uuid, ordem_servico_uuid, status, inicio, fim, distâncias do check-in/out, checkout_tipo, afastamento_qtd/minutos, obrigatorias_feitas/total</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/registros</code></td><td><code>registros:ler</code></td><td>criação</td><td>visita, loja, promotor, produto, tipo_registro, observacao, campos, ruptura, fotos (link temporário)</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/rupturas</code></td><td><code>rupturas:ler</code></td><td>criação</td><td>Registros com ruptura + resolvida_em e motivo_resolucao</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/ordens-servico</code></td><td><code>ordens_servico:ler</code></td><td>prazo_inicio</td><td>origem, status, obrigatoria, prioridade, prazo, horario_previsto, duracao_prevista_minutos, xp_previsto, cancelada_em</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/xp</code></td><td><code>xp:ler</code></td><td>competência</td><td>natureza, categoria, pontos, status, competencia, motivo, criado_em, resolvido_em</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/lojas</code></td><td><code>lojas:ler</code></td><td>cadastro</td><td>codigo_externo, cnpj, razão social, fantasia, endereço, coordenadas, rede, ramo, checkouts, vendedores, ativo</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/produtos</code></td><td><code>produtos:ler</code></td><td>cadastro</td><td>codigo_externo, descricao, codigo_barras, marca, departamento, secao, preco_tabela, ativo</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/mix</code></td><td><code>mix:ler</code></td><td>cadastro</td><td>Loja × produto (ou departamento/seção/marca), status_aprovacao (<code>null</code> = valendo)</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/usuarios</code></td><td><code>usuarios:ler</code></td><td>cadastro</td><td>uuid, nome, codigo_externo, tipo, supervisor_uuid, ativo</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/vendedores</code></td><td><code>vendedores:ler</code></td><td>cadastro</td><td>codigo_externo, nome, telefone, email, codigo_supervisor, ativo</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/supervisores</code></td><td><code>vendedores:ler</code></td><td>cadastro</td><td>codigo_externo, nome, telefone, email, ativo</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/carteira</code></td><td><code>vendedores:ler</code></td><td>—</td><td>Um par loja × vendedor por linha</td></tr>
  <tr><td><span class="metodo get">GET</span> <code>/pedidos</code></td><td><code>pedidos:ler</code></td><td>data_pedido</td><td>numero_pedido, numero_nf, loja, datas, valores, status_faturamento, faturado_em, itens, entregas</td></tr>
</table>
<p>Todas trazem <code>alterado_em</code>. No mix e na carteira, um vínculo removido some da lista; para conferir tudo, leia sem <code>updated_since</code>.</p>

<h3 id="fotos">Fotos</h3>
<p>As fotos não vêm no corpo. Cada registro traz <code>fotos: [{ uuid, url }]</code>, e a <code>url</code> é um link assinado que vale 30 minutos e não precisa de token. Baixe a foto na hora, ou leia o registro de novo para ganhar um link novo.</p>

<h2 id="escrita">Como enviar</h2>
<p>Todas as escritas são <code>PUT</code> com uma lista em <code>items</code>:</p>
@verbatim
<pre><code>PUT /lojas
{
  "items": [
    { "codigo_externo": "L1", "razao_social": "Mercado Bom Preço LTDA", "fantasia": "Bom Preço",
      "endereco": "Av. Brasil", "numero": "100", "cidade": "Manaus", "uf": "AM",
      "latitude": -3.1019, "longitude": -60.025, "vendedores": ["374", "680"] }
  ]
}</code></pre>
@endverbatim
<ul>
  <li><b>Upsert pelo código do ERP:</b> cria o que não existe e atualiza o que existe. Campo que não veio não é apagado.</li>
  <li><b>Item a item:</b> um item com erro não derruba o lote. Só um erro que vale para o lote todo (ex.: limite do plano) recusa tudo com <code>422</code>.</li>
  <li><b>Simulação:</b> <code>?dry_run=1</code> valida e devolve o mesmo resultado, sem gravar nada.</li>
  <li>Campo desconhecido é recusado no item, para pegar erro de digitação.</li>
</ul>
<p>Resposta:</p>
@verbatim
<pre><code>{
  "dry_run": false,
  "total": 3,
  "created": 1,
  "updated": 1,
  "errors": 1,
  "results": [
    { "item": 1, "codigo_externo": "L1", "result": "created" },
    { "item": 2, "codigo_externo": "L2", "result": "updated" },
    { "item": 3, "codigo_externo": "L3", "result": "error", "message": "Latitude inválida." }
  ]
}</code></pre>
@endverbatim
<p>Algumas rotas trazem também <code>details</code> (ex.: promotores criados sem licença disponível).</p>

<h3 id="ordem">Ordem da carga inicial</h3>
<p>Um cadastro só pode apontar para outro que já existe. Na primeira carga, envie nesta ordem:</p>
<ol>
  <li><code>/redes</code> e <code>/ramos-atividade</code> → <code>/lojas</code></li>
  <li><code>/departamentos</code> → <code>/secoes</code>; <code>/marcas</code> → <code>/produtos</code></li>
  <li><code>/supervisores</code> → <code>/vendedores</code> → <code>/carteira</code></li>
  <li><code>/lojas</code> + <code>/produtos</code> → <code>/mix</code></li>
  <li><code>/supervisores</code> → <code>/promotores</code></li>
  <li><code>/lojas</code> → <code>/pedidos</code> → <code>/entregas</code></li>
</ol>

<h3 id="rotas-escrita">Rotas e campos</h3>
<table>
  <tr><th>Rota</th><th>Permissão</th><th>Chave</th><th>Campos aceitos</th></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/lojas</code></td><td><code>lojas:escrever</code></td><td>codigo_externo</td><td>codigo_externo, cnpj, razao_social, fantasia, endereco, numero, bairro, cidade, uf, cep, telefone, email, latitude, longitude, rede, ramo_atividade, numero_checkouts, ativo, vendedores<br><small>Na criação: razao_social, fantasia, endereco, cidade, latitude e longitude são obrigatórios. <code>vendedores</code> (lista de códigos) substitui a carteira da loja.</small></td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/redes</code></td><td><code>lojas:escrever</code></td><td>descricao</td><td>descricao, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/ramos-atividade</code></td><td><code>lojas:escrever</code></td><td>descricao</td><td>descricao, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/produtos</code></td><td><code>produtos:escrever</code></td><td>codigo_externo</td><td>codigo_externo, descricao, codigo_barras, departamento, secao, marca, peso_kg, propriedade, preco_tabela, desconto_maximo_pct, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/marcas</code></td><td><code>produtos:escrever</code></td><td>descricao</td><td>descricao, propriedade (<code>PROPRIA</code> ou <code>CONCORRENTE</code>), ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/departamentos</code></td><td><code>produtos:escrever</code></td><td>descricao</td><td>descricao, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/secoes</code></td><td><code>produtos:escrever</code></td><td>departamento + descricao</td><td>descricao, departamento, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/mix</code></td><td><code>mix:escrever</code></td><td>loja × produto</td><td>codigo_externo_loja, codigo_externo_produto<br><small>Soma ao mix. Com <code>"replace": true</code> no corpo, cada loja do lote fica só com o que veio.</small></td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/supervisores</code></td><td><code>vendedores:escrever</code></td><td>codigo_externo</td><td>codigo_externo, nome, telefone, email, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/vendedores</code></td><td><code>vendedores:escrever</code></td><td>codigo_externo</td><td>codigo_externo, nome, telefone, email, codigo_supervisor, ativo</td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/carteira</code></td><td><code>vendedores:escrever</code></td><td>loja × vendedor</td><td>codigo_externo_loja, codigo_externo_vendedor<br><small>Cada loja do lote fica exatamente com os vendedores listados.</small></td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/promotores</code></td><td><code>usuarios:escrever</code></td><td>codigo_externo</td><td>codigo_externo, nome, email, senha_inicial, ativo, codigo_supervisor<br><small><code>senha_inicial</code> vale só na criação; depois o promotor troca no app e a API nunca a altera.</small></td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/pedidos</code></td><td><code>pedidos:escrever</code></td><td>numero_pedido</td><td>Ver <a href="#pedidos">Pedidos e entregas</a></td></tr>
  <tr><td><span class="metodo put">PUT</span> <code>/entregas</code></td><td><code>pedidos:escrever</code></td><td>pedido + horário</td><td>numero_pedido, data_entrega, observacao</td></tr>
</table>
<p>Nos cadastros de apoio (redes, ramos, marcas, departamentos, seções), o nome é a chave: maiúsculas, acentos e espaços nas pontas não diferenciam (<code>"ATACADÃO"</code> = <code>"atacadão"</code>). Lojas e produtos apontam para eles pelo nome.</p>
<p><code>ativo</code> aceita <code>true</code>/<code>false</code>. Nada é apagado pela API: para tirar um cadastro de uso, envie <code>"ativo": false</code>.</p>

<h3 id="pedidos">Pedidos e entregas</h3>
<p>Reenviar um pedido com o mesmo <code>numero_pedido</code> substitui o pedido inteiro (itens inclusive). Uma devolução pode vir com valores negativos.</p>
@verbatim
<pre><code>PUT /pedidos
{
  "items": [{
    "numero_pedido": "1001",
    "codigo_externo_loja": "L1",
    "numero_nf": "55123",
    "data_pedido": "2026-10-20",
    "data_previsao_entrega": "2026-10-23",
    "valor_total": 150.50,
    "valor_lucro_total": 32.10,
    "status_faturamento": "FATURADO",
    "faturado_em": "2026-10-21",
    "observacao": null,
    "itens": [
      { "codigo_externo_produto": "P1", "descricao_produto": "Refri 2L", "quantidade": 10,
        "preco_unitario": 15.05, "subtotal": 150.50, "custo_unitario": 11.84, "valor_lucro": 32.10 }
    ]
  }]
}</code></pre>
@endverbatim
<p>Obrigatórios: <code>numero_pedido</code>, <code>codigo_externo_loja</code>, <code>data_pedido</code> e ao menos um item com <code>codigo_externo_produto</code>, <code>descricao_produto</code> e <code>quantidade</code>. Os valores são opcionais.</p>
<p>A entrega é registrada à parte, quando acontece. Reenviar o mesmo pedido + horário não duplica:</p>
@verbatim
<pre><code>PUT /entregas
{ "items": [ { "numero_pedido": "1001", "data_entrega": "2026-10-22T10:00:00Z", "observacao": "Recebido pelo gerente" } ] }</code></pre>
@endverbatim

<h3 id="idempotencia">Reenvio seguro</h3>
<p>Se a conexão cair no meio de um envio, você não sabe se ele foi gravado. Mande o cabeçalho <code>Idempotency-Key</code> (1 a 100 caracteres, único por operação) e reenvie com a <b>mesma</b> chave:</p>
<ul>
  <li>A segunda chamada devolve a resposta da primeira, com <code>Idempotency-Replayed: true</code>, sem gravar de novo.</li>
  <li>A mesma chave com outro corpo é recusada (<code>422</code>); se a primeira ainda está rodando, <code>409</code>.</li>
  <li>A chave vale 24 horas. Uma resposta <code>5xx</code> não é guardada: pode reenviar com a mesma chave.</li>
</ul>
<pre><code>curl -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: carga-lojas-2026-10-22-parte-1" \
  -d @lojas.json "{{ $base }}/lojas"</code></pre>
<p>Sem a chave a escrita funciona normalmente; o upsert por código já impede cadastro duplicado.</p>

<h2 id="webhooks">Webhooks</h2>
<p>O administrador cadastra, na integração, a URL <code>https</code> que vai receber os avisos e marca os eventos. Cada evento exige a permissão de leitura correspondente.</p>
<table>
  <tr><th>Evento</th><th>Quando</th><th>Permissão</th></tr>
  <tr><td><code>visita.finalizada</code></td><td>O promotor fez o check-out</td><td><code>visitas:ler</code></td></tr>
  <tr><td><code>visita.cancelada</code></td><td>A visita foi cancelada</td><td><code>visitas:ler</code></td></tr>
  <tr><td><code>registro.criado</code></td><td>Um registro foi feito na visita</td><td><code>registros:ler</code></td></tr>
  <tr><td><code>ruptura.registrada</code></td><td>Um registro com ruptura</td><td><code>rupturas:ler</code></td></tr>
  <tr><td><code>ruptura.resolvida</code></td><td>A ruptura foi resolvida</td><td><code>rupturas:ler</code></td></tr>
  <tr><td><code>teste</code></td><td>Botão “Testar” no painel</td><td>—</td></tr>
</table>
<p>O Horus faz um <code>POST</code> JSON:</p>
@verbatim
<pre><code>POST https://seu-sistema.com.br/horus
X-Horus-Event: visita.finalizada
X-Horus-Delivery: 5d0c2f8e-…        (id do aviso)
X-Horus-Signature: t=1761138300,v1=9f2c…

{
  "event": "visita.finalizada",
  "created_at": "2026-10-22T13:05:00Z",
  "data": {
    "uuid": "…", "loja_uuid": "…", "loja_codigo_externo": "L1",
    "promotor_uuid": "…", "promotor_codigo_externo": "PR1",
    "ordem_servico_uuid": "…", "status": "FINALIZADA",
    "inicio": "2026-10-22T12:20:00Z", "fim": "2026-10-22T13:05:00Z"
  }
}</code></pre>
@endverbatim
<p>Nos eventos de registro e ruptura, <code>data</code> traz: uuid, visita_uuid, loja_codigo_externo, promotor_codigo_externo, tipo_registro, produto_codigo_externo, produto, ruptura, observacao, criado_em e resolvida_em.</p>
<ul>
  <li>Responda <code>2xx</code> em até 10 segundos. Processe depois, se for demorado.</li>
  <li>Sem <code>2xx</code>, o Horus tenta de novo após 1, 5, 15, 60, 240 e 720 minutos. Depois disso o aviso é abandonado (pode ser reenviado pelo painel).</li>
  <li>Após 20 falhas seguidas, o webhook é desativado e aparece assim no painel, com o motivo. O administrador reativa depois de corrigir.</li>
  <li>O mesmo aviso pode chegar mais de uma vez: ignore um <code>X-Horus-Delivery</code> já processado.</li>
  <li>Use o aviso como gatilho e, se precisar do registro completo, leia pela API.</li>
</ul>

<h3 id="assinatura">Conferir a assinatura</h3>
<p>Ao criar o webhook, o painel mostra um segredo (uma única vez). Cada aviso é assinado com ele:</p>
<pre><code>v1 = HMAC-SHA256(segredo, t + "." + corpo_bruto)   (hexadecimal)</code></pre>
<p>Calcule sobre o corpo <b>exatamente como chegou</b>, antes de converter o JSON. Recuse se não bater, ou se <code>t</code> tiver mais de 5 minutos (evita reenvio por terceiros).</p>
@verbatim
<pre><code>// Node.js
const crypto = require('crypto');
function avisoValido(corpoBruto, cabecalho, segredo) {
  const partes = Object.fromEntries(cabecalho.split(',').map(p => p.split('=')));
  if (Math.abs(Date.now() / 1000 - Number(partes.t)) > 300) return false;
  const esperado = crypto.createHmac('sha256', segredo).update(partes.t + '.' + corpoBruto).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(esperado), Buffer.from(partes.v1 || ''));
}</code></pre>
<pre><code>// PHP
function avisoValido(string $corpoBruto, string $cabecalho, string $segredo): bool {
    parse_str(str_replace(',', '&', $cabecalho), $p);
    if (abs(time() - (int) ($p['t'] ?? 0)) > 300) return false;
    $esperado = hash_hmac('sha256', $p['t'] . '.' . $corpoBruto, $segredo);
    return hash_equals($esperado, $p['v1'] ?? '');
}</code></pre>
@endverbatim
<p style="margin-top:40px;color:var(--muted);font-size:13px">Dúvidas ou problemas: o administrador da empresa vê, em <b>Integrações</b>, o histórico de chamadas de cada token (status e erro) e o histórico de entregas de cada webhook.</p>
</main>
</div>
</body>
</html>
