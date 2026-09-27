<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastro de lojas em lote via CSV — `codigo_externo` é a chave: se já existe loja com aquele
 * código na empresa, ATUALIZA; se não existe, CRIA. Nunca duplica.
 *
 * Regras:
 * - Tudo ou nada: com qualquer erro no arquivo, nada é gravado (o relatório diz a linha e o
 *   motivo). `simular` faz a validação completa e devolve o mesmo relatório sem gravar.
 * - Loja existente: célula vazia NÃO apaga o valor atual — dá pra subir um CSV só com as colunas
 *   que mudaram. Loja nova: razão social, fantasia, endereço, cidade, latitude e longitude são
 *   obrigatórios (mesma regra do cadastro manual).
 * - Rede e ramo de atividade vão pelo NOME (descrição) já cadastrado — nome desconhecido é erro,
 *   não cria cadastro novo (evita "Atacadao"/"Atacadão" virarem duas redes).
 * - Aceita separador `;` ou `,` e arquivo salvo pelo Excel pt-BR (Windows-1252), além de UTF-8.
 */
class ImportacaoPontosVenda
{
    public const MAX_LINHAS = 5000;

    /** Cabeçalho canônico — é o que o arquivo modelo traz, na ordem. */
    public const COLUNAS = [
        'codigo_externo', 'cnpj', 'razao_social', 'fantasia', 'endereco', 'numero', 'bairro', 'cidade',
        'cep', 'telefone', 'email', 'latitude', 'longitude', 'rede', 'ramo_atividade', 'numero_checkouts', 'ativo',
    ];

    private const OBRIGATORIOS_NA_CRIACAO = ['razao_social', 'fantasia', 'endereco', 'cidade', 'latitude', 'longitude'];

    /**
     * @return array{criadas: int, atualizadas: int, total: int, erros: list<array{linha: int, codigo_externo: string|null, mensagem: string}>, aplicado: bool}
     */
    public static function executar(Empresa $empresa, string $conteudo, bool $simular): array
    {
        $linhas = LeitorCsv::ler($conteudo, self::COLUNAS, ['codigo_externo'], self::MAX_LINHAS, 'loja');
        if (isset($linhas['erro'])) {
            return self::relatorio(0, 0, 0, [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]], false);
        }

        $redes = RedeLoja::where('empresa_id', $empresa->id)->get()->keyBy(fn ($r) => self::chaveNome($r->descricao));
        $ramos = RamoAtividade::where('empresa_id', $empresa->id)->get()->keyBy(fn ($r) => self::chaveNome($r->descricao));

        $codigos = array_values(array_filter(array_map(fn ($l) => $l['dados']['codigo_externo'] ?? null, $linhas)));
        $existentes = PontoVenda::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', $codigos)
            ->get()
            ->groupBy('codigo_externo');
        // Chave só com dígitos — o banco guarda o CNPJ como foi digitado (com ou sem máscara).
        $cnpjs = PontoVenda::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('cnpj')
            ->get(['cnpj', 'codigo_externo'])
            ->mapWithKeys(fn ($p) => [preg_replace('/\D/', '', $p->cnpj) => $p->codigo_externo]);

        $erros = [];
        $operacoes = [];
        $vistosNoArquivo = [];
        $cnpjsNoArquivo = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $bruto]) {
            $codigo = $bruto['codigo_externo'] ?? null;
            $erro = function (string $mensagem) use (&$erros, $numero, $codigo): void {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => $mensagem];
            };

            if (! $codigo) {
                $erro('Código externo vazio — ele é a chave da loja.');

                continue;
            }
            if (isset($vistosNoArquivo[$codigo])) {
                $erro("Código externo repetido no arquivo (já apareceu na linha {$vistosNoArquivo[$codigo]}).");

                continue;
            }
            $vistosNoArquivo[$codigo] = $numero;

            $atuais = $existentes->get($codigo);
            if ($atuais && $atuais->count() > 1) {
                $erro('Existe mais de uma loja com este código externo no sistema — corrija no cadastro antes de importar.');

                continue;
            }
            $atual = $atuais?->first();

            // Só o que veio preenchido entra — vazio numa loja existente mantém o valor atual.
            $dados = array_filter($bruto, fn ($v) => $v !== null && $v !== '');
            $mensagens = [];

            foreach (['latitude', 'longitude'] as $campo) {
                if (isset($dados[$campo])) {
                    $dados[$campo] = str_replace(',', '.', $dados[$campo]);
                }
            }

            if (isset($dados['rede'])) {
                $rede = $redes->get(self::chaveNome($dados['rede']));
                if ($rede) {
                    $dados['rede_loja_id'] = $rede->id;
                } else {
                    $mensagens[] = "Rede \"{$dados['rede']}\" não cadastrada.";
                }
                unset($dados['rede']);
            }
            if (isset($dados['ramo_atividade'])) {
                $ramo = $ramos->get(self::chaveNome($dados['ramo_atividade']));
                if ($ramo) {
                    $dados['ramo_atividade_id'] = $ramo->id;
                } else {
                    $mensagens[] = "Ramo de atividade \"{$dados['ramo_atividade']}\" não cadastrado.";
                }
                unset($dados['ramo_atividade']);
            }
            if (isset($dados['ativo'])) {
                $valor = LeitorCsv::booleano($dados['ativo']);
                if ($valor !== null) {
                    $dados['ativo'] = $valor;
                } else {
                    $mensagens[] = "Ativo \"{$dados['ativo']}\" inválido — use sim ou não.";
                    unset($dados['ativo']);
                }
            }

            if (! $atual) {
                foreach (self::OBRIGATORIOS_NA_CRIACAO as $campo) {
                    if (! isset($dados[$campo])) {
                        $mensagens[] = "Loja nova: {$campo} é obrigatório.";
                    }
                }
            }

            $validador = Validator::make($dados, [
                'codigo_externo' => ['string', 'max:50'],
                'cnpj' => ['string', 'max:20'],
                'razao_social' => ['string', 'max:255'],
                'fantasia' => ['string', 'max:255'],
                'latitude' => ['numeric', 'between:-90,90'],
                'longitude' => ['numeric', 'between:-180,180'],
                'endereco' => ['string', 'max:255'],
                'numero' => ['string', 'max:10'],
                'bairro' => ['string', 'max:100'],
                'cidade' => ['string', 'max:100'],
                'cep' => ['string', 'max:10'],
                'telefone' => ['string', 'max:50'],
                'email' => ['email', 'max:255'],
                'numero_checkouts' => ['integer', 'min:0'],
            ]);
            array_push($mensagens, ...$validador->errors()->all());

            // CNPJ é único por empresa — não pode ser de OUTRA loja (nem de outra linha do arquivo).
            if (isset($dados['cnpj'])) {
                $digitos = preg_replace('/\D/', '', $dados['cnpj']);
                $dono = $cnpjs->get($digitos);
                if ($cnpjs->has($digitos) && $dono !== $codigo) {
                    $mensagens[] = "CNPJ {$dados['cnpj']} já pertence a outra loja".($dono ? " (código {$dono})" : '').'.';
                } elseif (isset($cnpjsNoArquivo[$digitos])) {
                    $mensagens[] = "CNPJ repetido no arquivo (linha {$cnpjsNoArquivo[$digitos]}).";
                }
                $cnpjsNoArquivo[$digitos] = $numero;
            }

            if ($mensagens) {
                $erro(implode(' ', $mensagens));

                continue;
            }

            $operacoes[] = ['atual' => $atual, 'dados' => $dados];
        }

        $novas = count(array_filter($operacoes, fn ($o) => ! $o['atual']));
        $atualizadas = count($operacoes) - $novas;

        // Regra de negócio 4 (docs/02-API-BACKEND.md): limite de PDVs do plano vale pro lote todo.
        if ($empresa->limite_pontos_venda !== null && $novas > 0) {
            $existentesTotal = PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();
            if ($existentesTotal + $novas > $empresa->limite_pontos_venda) {
                $erros[] = ['linha' => 0, 'codigo_externo' => null, 'mensagem' => sprintf(
                    'O arquivo cria %d loja(s) nova(s), mas o plano permite %d e já existem %d.',
                    $novas, $empresa->limite_pontos_venda, $existentesTotal,
                )];
            }
        }

        if ($erros || $simular) {
            return self::relatorio($novas, $atualizadas, count($linhas), $erros, false);
        }

        DB::transaction(function () use ($empresa, $operacoes) {
            foreach ($operacoes as ['atual' => $atual, 'dados' => $dados]) {
                $atual
                    ? $atual->update($dados)
                    : PontoVenda::create([...$dados, 'empresa_id' => $empresa->id]);
            }
        });

        return self::relatorio($novas, $atualizadas, count($linhas), [], true);
    }

    private static function chaveNome(string $nome): string
    {
        return LeitorCsv::chaveNome($nome);
    }

    private static function relatorio(int $criadas, int $atualizadas, int $total, array $erros, bool $aplicado): array
    {
        return [
            'total' => $total,
            'criadas' => $criadas,
            'atualizadas' => $atualizadas,
            'erros' => $erros,
            'aplicado' => $aplicado,
        ];
    }
}
