<?php

namespace App\Support;

use App\Enums\Propriedade;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cadastros de apoio vindos do ERP (docs/96 §7.2): redes de loja, ramos de atividade, marcas,
 * departamentos e seções. Não têm código externo: a chave é o NOME, do mesmo jeito que a
 * importação de lojas e produtos já casa rede, ramo, marca, departamento e seção (sem diferença de
 * maiúscula ou espaço nas pontas). Existe → atualiza o que veio (ativo, propriedade); não existe →
 * cria. Seção é única dentro do departamento, então vai com o nome do departamento.
 *
 * Mesmo contrato das importações (`linhas` → relatório com `erros` por linha), pra rodar no
 * LoteIntegracao.
 */
class CadastroApoioErp
{
    public const COLUNAS = ['descricao', 'ativo'];

    public const COLUNAS_MARCA = ['descricao', 'propriedade', 'ativo'];

    public const COLUNAS_SECAO = ['descricao', 'departamento', 'ativo'];

    public static function redes(Empresa $empresa, array $linhas, bool $simular): array
    {
        return self::simples(RedeLoja::class, $empresa, $linhas, $simular);
    }

    public static function ramos(Empresa $empresa, array $linhas, bool $simular): array
    {
        return self::simples(RamoAtividade::class, $empresa, $linhas, $simular);
    }

    public static function departamentos(Empresa $empresa, array $linhas, bool $simular): array
    {
        return self::simples(DepartamentoAuditoria::class, $empresa, $linhas, $simular);
    }

    public static function marcas(Empresa $empresa, array $linhas, bool $simular): array
    {
        return self::simples(MarcaAuditoria::class, $empresa, $linhas, $simular, function (array &$dados, array &$mensagens): void {
            if (isset($dados['propriedade'])) {
                $propriedade = match (mb_strtolower(trim($dados['propriedade']))) {
                    'propria', 'própria', 'p' => Propriedade::PROPRIA,
                    'concorrente', 'c' => Propriedade::CONCORRENTE,
                    default => null,
                };
                $propriedade
                    ? $dados['propriedade'] = $propriedade
                    : $mensagens[] = "Propriedade \"{$dados['propriedade']}\" inválida: use propria ou concorrente.";
            }
        }, ['propriedade' => Propriedade::PROPRIA]);
    }

    public static function secoes(Empresa $empresa, array $linhas, bool $simular): array
    {
        $departamentos = DepartamentoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get()
            ->keyBy(fn ($d) => LeitorCsv::chaveNome($d->descricao));
        $existentes = SecaoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get()
            ->keyBy(fn ($s) => $s->departamento_id.'|'.LeitorCsv::chaveNome($s->descricao));

        $erros = [];
        $operacoes = [];
        $vistos = [];
        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $nome = $dados['descricao'] ?? null;
            $departamento = isset($dados['departamento']) && $dados['departamento'] !== '' ? $departamentos->get(LeitorCsv::chaveNome($dados['departamento'])) : null;
            $mensagens = [];
            if (! $nome) {
                $mensagens[] = 'descricao é obrigatória.';
            }
            if (! $departamento) {
                $mensagens[] = empty($dados['departamento'])
                    ? 'departamento é obrigatório (a seção é única dentro do departamento).'
                    : "Departamento \"{$dados['departamento']}\" não cadastrado: envie os departamentos antes.";
            }
            $ativo = self::ativo($dados, $mensagens);
            $chave = $departamento ? $departamento->id.'|'.LeitorCsv::chaveNome((string) $nome) : null;
            if ($chave && isset($vistos[$chave])) {
                $mensagens[] = "Seção repetida no lote (item {$vistos[$chave]}).";
            }
            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $nome, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }
            $vistos[$chave] = $numero;
            $operacoes[] = ['atual' => $existentes->get($chave), 'dados' => array_filter([
                'descricao' => mb_substr($nome, 0, 255),
                'departamento_id' => $departamento->id,
                'ativo' => $ativo,
            ], fn ($v) => $v !== null)];
        }

        return self::gravar(SecaoAuditoria::class, $empresa, $linhas, $erros, $operacoes, $simular);
    }

    /** Existentes por nome, pra o LoteIntegracao saber o que é criado e o que é atualizado. */
    public static function existentes(string $modelo, Empresa $empresa, array $nomes): array
    {
        $cadastrados = $modelo::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('descricao')
            ->mapWithKeys(fn ($d) => [LeitorCsv::chaveNome($d) => true]);

        return collect($nomes)->filter(fn ($n) => $cadastrados->has(LeitorCsv::chaveNome($n)))->mapWithKeys(fn ($n) => [$n => true])->all();
    }

    /**
     * @param  class-string<Model>  $modelo
     */
    private static function simples(string $modelo, Empresa $empresa, array $linhas, bool $simular, ?callable $extra = null, array $padraoNaCriacao = []): array
    {
        $existentes = $modelo::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get()
            ->keyBy(fn ($m) => LeitorCsv::chaveNome($m->descricao));

        $erros = [];
        $operacoes = [];
        $vistos = [];
        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $nome = $dados['descricao'] ?? null;
            $mensagens = [];
            if (! $nome) {
                $mensagens[] = 'descricao é obrigatória.';
            } elseif (mb_strlen($nome) > 255) {
                $mensagens[] = 'descricao passa de 255 caracteres.';
            }
            $chave = $nome ? LeitorCsv::chaveNome($nome) : null;
            if ($chave !== null && isset($vistos[$chave])) {
                $mensagens[] = "Nome repetido no lote (item {$vistos[$chave]}).";
            }
            $dados['ativo'] = self::ativo($dados, $mensagens);
            if ($extra) {
                $extra($dados, $mensagens);
            }
            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $nome, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }
            $vistos[$chave] = $numero;
            $atual = $existentes->get($chave);
            $gravar = array_filter($dados, fn ($v) => $v !== null && $v !== '');
            // O nome é a chave: "ATACADÃO" casa com "Atacadão" e não renomeia o cadastro.
            $operacoes[] = ['atual' => $atual, 'dados' => $atual ? array_diff_key($gravar, ['descricao' => true]) : $gravar + $padraoNaCriacao];
        }

        return self::gravar($modelo, $empresa, $linhas, $erros, $operacoes, $simular);
    }

    private static function ativo(array $dados, array &$mensagens): ?bool
    {
        if (! isset($dados['ativo']) || $dados['ativo'] === '') {
            return null;
        }
        $valor = LeitorCsv::booleano($dados['ativo']);
        if ($valor === null) {
            $mensagens[] = "Ativo \"{$dados['ativo']}\" inválido: use true ou false.";
        }

        return $valor;
    }

    /** @param  class-string<Model>  $modelo */
    private static function gravar(string $modelo, Empresa $empresa, array $linhas, array $erros, array $operacoes, bool $simular): array
    {
        $novas = count(array_filter($operacoes, fn ($o) => ! $o['atual']));
        $relatorio = ['total' => count($linhas), 'criadas' => $novas, 'atualizadas' => count($operacoes) - $novas, 'erros' => $erros, 'aplicado' => false];
        if ($erros || $simular) {
            return $relatorio;
        }

        DB::transaction(function () use ($modelo, $empresa, $operacoes) {
            foreach ($operacoes as ['atual' => $atual, 'dados' => $dados]) {
                $atual ? $atual->update($dados) : $modelo::create([...$dados, 'empresa_id' => $empresa->id]);
            }
        });

        return ['aplicado' => true] + $relatorio;
    }
}
