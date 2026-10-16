<?php

namespace App\Console\Commands;

use App\Enums\PlanoEmpresa;
use App\Enums\Propriedade;
use App\Enums\UserType;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Teste de fluxo de ponta a ponta no banco de DESENVOLVIMENTO (grava de verdade): cria uma empresa
 * nova com loja, Mix, marcas e usuários, e percorre pelas rotas da API — com os middlewares reais
 * (login do app com aparelho, licença, canal mobile) — o caminho do admin e do promotor:
 *
 *   versão da API → admin monta o Mix e os formulários (Mix da loja e concorrentes por marca,
 *   docs/85) → promotor entra pelo app, baixa formulários e Mix, faz check-in, checklist de
 *   presença, embalagens por marca (inclusive marca nova offline), checkout → admin vê "quem
 *   ocupa o espaço", aprova e junta marcas.
 *
 * Para no primeiro passo que quebrar, mostrando a resposta. No fim imprime os logins pra abrir os
 * dados no admin/app. Nunca roda em produção.
 */
class TestarFluxoCampo extends Command
{
    protected $signature = 'fluxo:testar';

    protected $description = 'Percorre o fluxo admin + promotor pela API no banco de desenvolvimento (grava os dados)';

    private const SENHA = 'fluxo12345';

    private int $passo = 0;

    // Guard padrão de antes da 1ª requisição: o `shouldUse('sanctum')` do auth grava na própria
    // config, então ler a config depois devolveria `sanctum` (num servidor, cada requisição
    // começa com a config limpa).
    private string $guardPadrao = 'web';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Não roda em produção.');

            return self::FAILURE;
        }

        $this->guardPadrao = (string) config('auth.defaults.guard');

        try {
            $this->executar();
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error('FALHOU: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executar(): void
    {
        $sufixo = now()->format('YmdHis');
        $this->info("Teste de fluxo {$sufixo} — banco: ".config('database.connections.'.config('database.default').'.database'));

        // ---------------------------------------------------------------- massa de dados
        $empresa = Empresa::create([
            'razao_social' => "Fluxo Automatizado {$sufixo} LTDA",
            'nome_fantasia' => "Fluxo {$sufixo}",
            'cnpj' => substr(str_pad($sufixo, 14, '0'), 0, 14),
            'plano' => PlanoEmpresa::GRATUITO,
            'limite_usuarios' => 10,
            'limite_pontos_venda' => 10,
            'ativo' => true,
        ]);
        $admin = Usuario::create([
            'empresa_id' => $empresa->id, 'nome' => 'Admin Fluxo', 'email' => "fluxo{$sufixo}.admin@teste.local",
            'senha_hash' => Hash::make(self::SENHA), 'user_type' => UserType::ADMIN, 'ativo' => true,
        ]);
        $promotor = Usuario::create([
            'empresa_id' => $empresa->id, 'nome' => 'Promotor Fluxo', 'email' => "fluxo{$sufixo}.promotor@teste.local",
            'senha_hash' => Hash::make(self::SENHA), 'user_type' => UserType::PROMOTOR, 'ativo' => true, 'licenca_mobile' => true,
        ]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza', 'ativo' => true]);
        $amaciante = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id, 'descricao' => 'Amaciante', 'ativo' => true]);
        $sabao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id, 'descricao' => 'Sabão', 'ativo' => true]);
        $nossa = MarcaAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Nossa Marca', 'propriedade' => Propriedade::PROPRIA, 'ativo' => true]);
        $downy = MarcaAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Downy', 'propriedade' => Propriedade::CONCORRENTE, 'ativo' => true]);
        $brinorte = MarcaAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Brinorte', 'propriedade' => Propriedade::CONCORRENTE, 'ativo' => true]);
        $produtos = collect(['Sabão Nossa 1kg', 'Sabão Nossa 2kg', 'Sabão Nossa Líquido 3L'])->map(fn ($d) => ProdutoAuditoria::create([
            'empresa_id' => $empresa->id, 'descricao' => $d, 'propriedade' => Propriedade::PROPRIA, 'ativo' => true,
            'departamento_id' => $departamento->id, 'secao_id' => $sabao->id, 'marca_id' => $nossa->id,
        ]));
        $produtoConcorrente = ProdutoAuditoria::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Sabão Concorrente 1kg', 'propriedade' => Propriedade::CONCORRENTE, 'ativo' => true,
            'departamento_id' => $departamento->id, 'secao_id' => $sabao->id,
        ]);
        $loja = PontoVenda::create([
            'empresa_id' => $empresa->id, 'razao_social' => 'Mercado Fluxo LTDA', 'fantasia' => "Mercado Fluxo {$sufixo}",
            'latitude' => -3.1019, 'longitude' => -60.0250, 'endereco' => 'Rua do Teste, 1', 'bairro' => 'Centro', 'cidade' => 'Manaus', 'ativo' => true,
        ]);
        $this->ok('Massa de dados criada', "empresa \"{$empresa->nome_fantasia}\", loja, 4 produtos, 3 marcas");

        // ---------------------------------------------------------------- versão (doc 84)
        $r = $this->chamar('GET', '/versao');
        $this->esperar($r, 200);
        $this->confirmar(! empty($r['corpo']['versao']) && $r['cabecalhos']->has('X-Api-Version'), 'versão da API no corpo e no cabeçalho', $r);
        $this->ok('Versão da API', "{$r['corpo']['versao']} · {$r['corpo']['ambiente']}");

        // ---------------------------------------------------------------- admin
        $r = $this->chamar('POST', '/auth/login', ['email' => $admin->email, 'senha' => self::SENHA]);
        $this->esperar($r, 200);
        $tokenAdmin = $r['corpo']['token'];
        $this->ok('Admin entrou (web)');

        foreach ([...$produtos, $produtoConcorrente] as $p) {
            $this->esperar($this->chamar('POST', "/pontos-venda/{$loja->uuid}/sortimento", ['tipo_item' => 'PRODUTO', 'produto_uuid' => $p->uuid], $tokenAdmin), 201);
        }
        $this->ok('Admin montou o Mix da loja', '3 nossos + 1 concorrente');

        $r = $this->chamar('POST', '/tipos-registro', [
            'descricao' => "Presença no Mix {$sufixo}", 'icone' => 'check-all',
            'acao_obrigatoria' => true, 'escopo_acao' => 'SEMPRE',
            'granularidade_padrao' => 'PRODUTO', 'lista_produtos_origem' => 'MIX_LOJA',
            'campos' => [['chave' => 'na_gondola', 'rotulo' => 'Está na gôndola?', 'tipo_campo' => 'BOOLEANO', 'obrigatorio' => true]],
        ], $tokenAdmin);
        $this->esperar($r, 201);
        $presenca = $r['corpo']['tipo_registro'];
        $this->confirmar($presenca['lista_produtos_origem'] === 'MIX_LOJA', 'formulário de presença com lista pelo Mix da loja', $r);
        $this->ok('Admin criou "Cada produto" pelo Mix da loja (checklist de presença)');

        $r = $this->chamar('POST', '/tipos-registro', [
            'descricao' => 'X', 'granularidade_padrao' => 'MARCA', 'marcas_uuids' => [$nossa->uuid],
        ], $tokenAdmin);
        $this->esperar($r, 422);
        $this->ok('Lista de marcas recusa marca própria', '422 como esperado');

        $r = $this->chamar('POST', '/tipos-registro', [
            'descricao' => "Concorrentes Amaciante {$sufixo}", 'icone' => 'tag-multiple',
            'acao_obrigatoria' => true, 'escopo_acao' => 'LINHA_AUSENTE', 'escopo_tipo_vinculo' => 'SECAO', 'escopo_secao_uuid' => $amaciante->uuid,
            'granularidade_padrao' => 'MARCA', 'marcas_uuids' => [$downy->uuid, $brinorte->uuid],
            'permite_marca_nova' => true, 'varias_embalagens' => true,
            'campos' => [
                ['chave' => 'preco', 'rotulo' => 'Preço na gôndola', 'tipo_campo' => 'MOEDA', 'obrigatorio' => true],
                ['chave' => 'promocao', 'rotulo' => 'Está em promoção?', 'tipo_campo' => 'BOOLEANO'],
                ['chave' => 'frentes', 'rotulo' => 'Quantas frentes?', 'tipo_campo' => 'NUMERO'],
            ],
        ], $tokenAdmin);
        $this->esperar($r, 201);
        $concorrentes = $r['corpo']['tipo_registro'];
        $this->confirmar(array_column($concorrentes['marcas_predefinidas'], 'descricao') === ['Downy', 'Brinorte'], 'marcas da lista na ordem escolhida', $r);
        $this->ok('Admin criou "Cada marca concorrente" na linha Amaciante', 'Downy, Brinorte + marca nova');

        // ---------------------------------------------------------------- promotor (app)
        $app = ['X-Client' => 'mobile', 'X-App-Version' => '1.1.0'];
        $r = $this->chamar('POST', '/auth/login', [
            'email' => $promotor->email, 'senha' => self::SENHA,
            'dispositivo_identificador' => "fluxo-{$sufixo}", 'dispositivo_nome' => 'Celular do teste', 'dispositivo_plataforma' => 'android',
        ], null, $app);
        $this->esperar($r, 200);
        $tokenApp = $r['corpo']['token'];
        $this->ok('Promotor entrou pelo app', 'aparelho vinculado, versão 1.1.0');

        $r = $this->chamar('GET', '/tipos-registro?ativo=1', null, $tokenApp, $app);
        $this->esperar($r, 200);
        $porId = collect($r['corpo']['tipos_registro'] ?? $r['corpo']['data'] ?? [])->keyBy('id');
        $this->confirmar($porId->has($presenca['id']) && $porId->has($concorrentes['id']), 'os dois formulários chegam no app', $r);
        $this->confirmar(count($porId[$concorrentes['id']]['marcas_predefinidas'] ?? []) === 2, 'lista de marcas chega no app', $r);
        $this->ok('App baixou os formulários', 'com a lista de marcas e a origem da lista');

        $r = $this->chamar('GET', "/pontos-venda/{$loja->uuid}", null, $tokenApp, $app);
        $this->esperar($r, 200);
        // Mesma regra do app (lib/acaoObrigatoria.ts › produtosDaListaGuiada): só os nossos.
        $listaMix = collect($r['corpo']['ponto_venda']['sortimento'] ?? [])
            ->filter(fn ($i) => $i['tipo_item'] === 'PRODUTO' && $i['produto'] && ($i['produto']['propriedade'] ?? null) !== 'CONCORRENTE' && ($i['status_aprovacao'] ?? null) !== 'PENDENTE')
            ->pluck('produto.id')->values();
        $this->confirmar($listaMix->count() === 3, 'lista guiada do Mix com os 3 nossos produtos (sem o concorrente)', $r);
        $this->ok('App montou a lista do Mix da loja', '3 produtos (concorrente fora)');

        $r = $this->chamar('POST', '/visitas', [
            'ponto_venda_uuid' => $loja->uuid, 'latitude' => -3.1019, 'longitude' => -60.0250, 'idempotency_key' => (string) Str::uuid(),
        ], $tokenApp, $app);
        $this->esperar($r, 201);
        $visita = $r['corpo']['visita']['id'];
        $this->ok('Check-in na loja');

        foreach ($listaMix as $i => $produtoUuid) {
            $r = $this->registro($visita, $tokenApp, $app, [
                'tipo_registro_uuid' => $presenca['id'], 'produto_auditoria_uuid' => $produtoUuid, 'tipo_vinculo' => 'PRODUTO',
                'valores_campos' => ['na_gondola' => $i === 2 ? '0' : '1'],
            ]);
            $this->esperar($r, 201);
        }
        $this->ok('Checklist de presença salvo', '3 produtos (1 fora da gôndola)');

        $r = $this->registro($visita, $tokenApp, $app, ['tipo_registro_uuid' => $concorrentes['id'], 'marca_uuid' => $downy->uuid, 'valores_campos' => ['preco' => '12.00']]);
        $this->esperar($r, 422);
        $this->ok('Embalagem sem quantidade/unidade é recusada', '422 como esperado');

        $embalagens = [
            [$downy->uuid, 500, 'ML', '12.00', '1', '2'],
            [$downy->uuid, 1.5, 'L', '25.90', '0', '1'],
            [$brinorte->uuid, 2, 'L', '5.99', '0', '4'],
        ];
        foreach ($embalagens as [$marca, $qtd, $un, $preco, $promo, $frentes]) {
            $r = $this->registro($visita, $tokenApp, $app, [
                'tipo_registro_uuid' => $concorrentes['id'], 'marca_uuid' => $marca,
                'embalagem_quantidade' => $qtd, 'embalagem_unidade' => $un,
                'valores_campos' => ['preco' => $preco, 'promocao' => $promo, 'frentes' => $frentes],
            ]);
            $this->esperar($r, 201);
        }
        $primeiro = $r['corpo']['registro'];
        $this->confirmar(($primeiro['secao']['descricao'] ?? null) === 'Amaciante' && ($primeiro['embalagem']['descricao'] ?? null) === '2 L', 'registro grava a linha do escopo e a embalagem', $r);
        $this->ok('Embalagens das marcas da lista salvas', 'Downy 500 ml e 1,5 L, Brinorte 2 L');

        // Marca nova cadastrada offline: o celular gera o uuid; reenvio da fila não duplica.
        $uuidYpe = (string) Str::uuid();
        $chave = (string) Str::uuid();
        $corpoYpe = [
            'tipo_registro_uuid' => $concorrentes['id'], 'idempotency_key' => $chave,
            'marca_uuid' => $uuidYpe, 'marca_nova_descricao' => 'Ypê Fluxo',
            'embalagem_quantidade' => 1, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '8.50'],
        ];
        $this->esperar($this->chamar('POST', "/visitas/{$visita}/registros", $corpoYpe, $tokenApp, $app), 201);
        $this->esperar($this->chamar('POST', "/visitas/{$visita}/registros", $corpoYpe, $tokenApp, $app), 200);
        $this->ok('Marca nova offline ("Ypê Fluxo") salva e reenvio não duplicou');

        // Mesmo nome de uma marca existente: usa a existente.
        $r = $this->registro($visita, $tokenApp, $app, [
            'tipo_registro_uuid' => $concorrentes['id'], 'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'DOWNY',
            'embalagem_quantidade' => 2, 'embalagem_unidade' => 'L', 'valores_campos' => ['preco' => '39.90'],
        ]);
        $this->esperar($r, 201);
        $this->confirmar(($r['corpo']['registro']['marca']['id'] ?? null) === $downy->uuid, '"DOWNY" digitado cai na Downy existente', $r);
        $this->ok('"DOWNY" digitado pelo promotor reaproveitou a Downy');

        // Uma duplicada de verdade, pra o admin juntar.
        $this->esperar($this->registro($visita, $tokenApp, $app, [
            'tipo_registro_uuid' => $concorrentes['id'], 'marca_uuid' => (string) Str::uuid(), 'marca_nova_descricao' => 'Downy Concentrado Fluxo',
            'embalagem_quantidade' => 500, 'embalagem_unidade' => 'ML', 'valores_campos' => ['preco' => '15.00'],
        ]), 201);

        $this->esperar($this->chamar('PATCH', "/visitas/{$visita}/checkout", ['latitude' => -3.1019, 'longitude' => -60.0250], $tokenApp, $app), 200);
        $this->ok('Checkout');

        // ---------------------------------------------------------------- admin vê e organiza
        $hoje = now()->toDateString();
        $r = $this->chamar('GET', "/tipos-registro/{$concorrentes['id']}/concorrentes?inicio={$hoje}&fim={$hoje}", null, $tokenAdmin);
        $this->esperar($r, 200);
        $linhas = collect($r['corpo']['linhas']);
        $primeira = $linhas->first();
        $this->confirmar($primeira['marca']['descricao'] === 'Brinorte' && (float) $primeira['preco_por_referencia'] === 3.0, 'mais barato por litro é Brinorte 2 L a R$ 3,00/L', $r);
        $this->ok('Admin vê "Quem ocupa o espaço"', $linhas->map(fn ($l) => "{$l['marca']['descricao']} {$l['embalagem']} R$ {$l['preco_por_referencia']}/{$l['referencia']}")->join(' · '));

        $r = $this->chamar('GET', '/marcas-auditoria?a_aprovar=1', null, $tokenAdmin);
        $this->esperar($r, 200);
        $aAprovar = collect($r['corpo']['marcas'])->keyBy('descricao');
        $this->confirmar($aAprovar->has('Ypê Fluxo') && $aAprovar->has('Downy Concentrado Fluxo'), 'as duas marcas novas estão "a aprovar"', $r);
        $this->ok('Fila de marcas a aprovar', $aAprovar->keys()->join(', '));

        $this->esperar($this->chamar('POST', "/marcas-auditoria/{$uuidYpe}/aprovar", [], $tokenAdmin), 200);
        $this->ok('Admin aprovou "Ypê Fluxo"');

        $duplicada = $aAprovar['Downy Concentrado Fluxo']['id'];
        $this->esperar($this->chamar('POST', "/marcas-auditoria/{$duplicada}/juntar", ['destino_uuid' => $downy->uuid], $tokenAdmin), 200);
        $r = $this->chamar('GET', "/tipos-registro/{$concorrentes['id']}/concorrentes?inicio={$hoje}&fim={$hoje}", null, $tokenAdmin);
        $this->confirmar(! collect($r['corpo']['linhas'])->contains(fn ($l) => $l['marca']['descricao'] === 'Downy Concentrado Fluxo'), 'marca juntada some da visão', $r);
        $this->ok('Admin juntou "Downy Concentrado Fluxo" na Downy', 'o registro passou pra Downy');

        // ---------------------------------------------------------------- resumo
        $this->newLine();
        $this->info("Tudo certo: {$this->passo} passos.");
        $this->line('Dados gravados no banco de desenvolvimento. Pra conferir na tela:');
        $this->line("  Admin:    {$admin->email} / ".self::SENHA);
        $this->line("  Promotor: {$promotor->email} / ".self::SENHA);
        $this->line("  Loja:     {$loja->fantasia}");
    }

    // ---------------------------------------------------------------- apoio

    private function registro(string $visita, string $token, array $headers, array $dados): array
    {
        return $this->chamar('POST', "/visitas/{$visita}/registros", ['idempotency_key' => (string) Str::uuid(), ...$dados], $token, $headers);
    }

    /** @return array{status: int, corpo: mixed, cabecalhos: \Symfony\Component\HttpFoundation\ResponseHeaderBag, metodo: string, uri: string} */
    private function chamar(string $metodo, string $uri, ?array $corpo = null, ?string $token = null, array $headers = []): array
    {
        // Cada requisição começa como num processo novo: sem o usuário da anterior e com o guard
        // padrão de volta — senão o `auth:sanctum` da anterior fica como padrão e o filtro de
        // empresa (BelongsToEmpresa) entra em recursão ao resolver o usuário do token.
        $auth = app('auth');
        $auth->forgetGuards();
        $auth->shouldUse($this->guardPadrao);

        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        if ($token) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }
        foreach ($headers as $nome => $valor) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $nome))] = $valor;
        }

        $request = Request::create('/api'.$uri, $metodo, [], [], [], $server, $corpo !== null ? json_encode($corpo) : null);
        $kernel = app(Kernel::class);
        $resposta = $kernel->handle($request);
        $kernel->terminate($request, $resposta);

        return [
            'status' => $resposta->getStatusCode(),
            'corpo' => json_decode((string) $resposta->getContent(), true),
            'cabecalhos' => $resposta->headers,
            'metodo' => $metodo,
            'uri' => $uri,
        ];
    }

    private function esperar(array $r, int $status): void
    {
        if ($r['status'] !== $status) {
            throw new RuntimeException("{$r['metodo']} {$r['uri']} devolveu {$r['status']} (esperado {$status}):\n".json_encode($r['corpo'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    private function confirmar(bool $condicao, string $oQue, array $r): void
    {
        if (! $condicao) {
            throw new RuntimeException("{$oQue} — não confere. {$r['metodo']} {$r['uri']}:\n".mb_substr(json_encode($r['corpo'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0, 3000));
        }
    }

    private function ok(string $texto, ?string $detalhe = null): void
    {
        $this->passo++;
        $this->line(sprintf('  <fg=green>✓</> %2d. %s%s', $this->passo, $texto, $detalhe ? " <fg=gray>— {$detalhe}</>" : ''));
    }
}
