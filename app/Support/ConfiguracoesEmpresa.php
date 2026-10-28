<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;
use Illuminate\Support\Facades\DB;

/**
 * Leitura e gravação em lote da tela de Configurações (docs/62). A tabela `parametros` continua
 * chave/valor por empresa; aqui o catálogo (ParametrosPadrao + ParametrosMetadados) é a fonte da
 * lista, então uma empresa que ainda não tem a linha vê o padrão e a linha nasce ao salvar.
 */
final class ConfiguracoesEmpresa
{
    private const VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    /**
     * Áreas com seus itens, com o valor efetivo da empresa.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogo(Empresa $empresa): array
    {
        $linhas = Parametro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get()->keyBy('chave');
        // Quem mexeu por último em cada configuração (auditoria, App\Support\HistoricoParametros).
        $ultimas = \App\Models\ParametroHistorico::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('id', fn ($q) => $q->from('parametro_historico')->where('empresa_id', $empresa->id)->selectRaw('max(id)')->groupBy('chave'))
            ->get()->keyBy('chave');

        $itens = collect(ParametrosPadrao::CATALOGO)
            // Recurso de plano desligado: os parâmetros dele nem aparecem (ex.: régua de XP, docs/88 §2.1).
            ->reject(fn (array $padrao, string $chave) => ($campo = ParametrosMetadados::de($chave)['so_com'] ?? null) !== null && ! $empresa->{$campo})
            ->map(function (array $padrao, string $chave) use ($linhas, $empresa, $ultimas) {
            $meta = ParametrosMetadados::de($chave);
            /** @var Parametro|null $linha */
            $linha = $linhas->get($chave);
            $tipo = $meta['tipo'];
            $semLimite = ($meta['ativo_significa'] ?? null) === 'sem_limite';

            $ativo = $linha?->ativo ?? true;
            // Linha desativada volta ao padrão pra quem consome (exceto "sem limite", onde desativar
            // é o próprio valor) — a tela mostra o que está valendo de verdade.
            $valorBruto = ($linha && $linha->ativo) || ($linha && $semLimite) ? $linha->valor : $padrao['valor'];
            $valor = self::normalizar($tipo, (string) $valorBruto, $meta, $padrao['valor']);

            $alterado = $semLimite
                ? ($linha !== null && (! $linha->ativo || $valor !== $padrao['valor']))
                : ($linha !== null && $linha->ativo && $valor !== $padrao['valor']);

            return [
                'chave' => $chave,
                'area' => $meta['area'],
                'rotulo' => $meta['rotulo'],
                'ajuda' => $meta['ajuda'],
                'descricao_tecnica' => $meta['descricao_tecnica'],
                'tipo' => $tipo,
                'unidade' => $meta['unidade'] ?? null,
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
                'opcoes' => isset($meta['opcoes'])
                    ? collect($meta['opcoes'])->map(fn ($rotulo, $v) => ['valor' => (string) $v, 'rotulo' => $rotulo])->values()->all()
                    : null,
                'depende_de' => $meta['depende_de'] ?? null,
                // Recurso não contratado: a tela mostra travado, com o motivo (o lote recusa mexer).
                'bloqueado' => self::bloqueioDeContrato($empresa, $chave),
                'grupo' => $meta['grupo'] ?? null,
                'ultima_alteracao' => ($u = $ultimas->get($chave)) ? ['por' => $u->usuario_nome, 'origem' => $u->origem, 'em' => $u->created_at?->toIso8601String()] : null,
                'ativo_significa' => $meta['ativo_significa'] ?? null,
                'padrao' => self::normalizar($tipo, $padrao['valor'], $meta, $padrao['valor']),
                'valor' => $valor,
                'ativo' => $ativo,
                'cadastrado' => $linha !== null,
                'alterado_do_padrao' => $alterado,
            ];
        });

        return collect(ParametrosMetadados::AREAS)
            ->map(fn (array $area, string $chave) => [
                'chave' => $chave,
                'rotulo' => $area['rotulo'],
                'ajuda' => $area['ajuda'],
                'itens' => $itens->where('area', $chave)->values()->all(),
            ])
            ->filter(fn (array $area) => $area['itens'] !== [])
            ->values()
            ->all();
    }

    /** Mensagem de erro do valor pro tipo da chave, ou null se vale. */
    public static function erroDoValor(string $chave, mixed $valor): ?string
    {
        $meta = ParametrosMetadados::de($chave);
        $texto = is_bool($valor) ? ($valor ? 'true' : 'false') : trim((string) $valor);

        switch ($meta['tipo']) {
            case 'bool':
                return in_array(strtolower($texto), ['true', 'false'], true) ? null : 'Use ligado ou desligado.';
            case 'hora':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $texto) ? null : 'Use o formato HH:mm.';
            case 'lista':
                return array_key_exists($texto, $meta['opcoes']) ? null : 'Escolha uma das opções da lista.';
            case 'numero_ou_zero':
                if ($texto === '0') {
                    return null;
                }
                // segue a regra de faixa abaixo
            case 'numero':
            case 'numero_ou_sem_limite':
                if (! preg_match('/^\d+$/', $texto)) {
                    return 'Informe um número inteiro.';
                }
                $n = (int) $texto;
                if ($n < $meta['min'] || $n > $meta['max']) {
                    return "Informe um valor entre {$meta['min']} e {$meta['max']}.";
                }

                return null;
            default:
                return 'Esta configuração só pode ser alterada pelo suporte.';
        }
    }

    /**
     * Aplica as alterações numa transação (tudo ou nada) e cria a linha de quem ainda não a tinha.
     * O chamador já validou chaves e valores (LoteParametrosRequest).
     *
     * @param  list<array{chave: string, valor?: mixed, ativo?: bool}>  $alteracoes
     */
    /** Motivo de a configuração estar travada por recurso não contratado no plano; null = liberada. */
    public static function bloqueioDeContrato(?Empresa $empresa, string $chave): ?string
    {
        $soCom = ParametrosMetadados::de($chave)['so_com'] ?? null;
        if ($soCom !== null && ! $empresa?->{$soCom}) {
            return 'Recurso do plano não habilitado para esta empresa.';
        }
        $contrato = ParametrosMetadados::de($chave)['exige_contrato'] ?? null;
        if ($contrato === null || ($empresa && $empresa->{$contrato['campo']})) {
            return null;
        }

        return $contrato['motivo'];
    }

    public static function salvarLote(Empresa $empresa, array $alteracoes): void
    {
        DB::transaction(function () use ($empresa, $alteracoes) {
            foreach ($alteracoes as $alteracao) {
                $chave = $alteracao['chave'];
                $padrao = ParametrosPadrao::CATALOGO[$chave];
                $meta = ParametrosMetadados::de($chave);
                $linha = Parametro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('chave', $chave)->first()
                    ?? new Parametro(['empresa_id' => $empresa->id, 'chave' => $chave, 'valor' => $padrao['valor'], 'descricao' => $padrao['descricao'], 'ativo' => true]);

                if (array_key_exists('valor', $alteracao) && $alteracao['valor'] !== null) {
                    $v = $alteracao['valor'];
                    $linha->valor = $meta['tipo'] === 'bool'
                        ? (in_array(strtolower(is_bool($v) ? ($v ? 'true' : 'false') : trim((string) $v)), self::VERDADEIROS, true) ? 'true' : 'false')
                        : trim((string) $v);
                    // Editar o valor de uma linha desativada é querer que ele valha.
                    $linha->ativo = true;
                }

                // Só "sem limite" usa `ativo` como interruptor (RaioCheckin); nas demais, mexer em
                // `ativo` é o "Avançado" (a requisição recusa).
                if (array_key_exists('ativo', $alteracao) && $alteracao['ativo'] !== null) {
                    $linha->ativo = (bool) $alteracao['ativo'];
                }

                $linha->save();
            }
        });
    }

    /** @param array<string, mixed> $meta */
    private static function normalizar(string $tipo, string $valor, array $meta, string $padrao): string
    {
        $valor = trim($valor);

        return match ($tipo) {
            'bool' => in_array(strtolower($valor), self::VERDADEIROS, true) ? 'true' : 'false',
            'lista' => array_key_exists(strtoupper($valor), $meta['opcoes']) ? strtoupper($valor) : $padrao,
            'numero', 'numero_ou_zero', 'numero_ou_sem_limite' => preg_match('/^\d+$/', $valor) ? $valor : $padrao,
            'hora' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) ? $valor : $padrao,
            default => $valor,
        };
    }
}
