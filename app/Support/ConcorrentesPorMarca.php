<?php

namespace App\Support;

use App\Enums\Propriedade;
use App\Enums\StatusAprovacao;
use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Enums\UnidadeEmbalagem;
use App\Models\MarcaAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Concorrentes por marca e embalagem (docs/85): marca do registro (inclusive a cadastrada pelo
 * promotor offline), vínculo gravado no registro e a visão "quem ocupa o espaço" com R$ por
 * litro/kg — que nunca é gravado, sempre calculado do preço ÷ embalagem.
 */
class ConcorrentesPorMarca
{
    /**
     * Marca do registro. Com `marca_nova_descricao`, o celular gerou o uuid offline: se já existe
     * uma marca com esse uuid (reenvio) ou com o mesmo nome na empresa (outro promotor cadastrou
     * "Downy" antes), usa ela; senão cria concorrente "a aprovar".
     */
    public static function resolverMarca(?string $uuid, ?string $descricaoNova, Usuario $usuario): ?MarcaAuditoria
    {
        if ($uuid && Str::isUuid($uuid) && ($marca = MarcaAuditoria::where('uuid', $uuid)->first())) {
            return $marca;
        }
        $descricao = trim((string) $descricaoNova);
        if ($descricao === '') {
            return null;
        }
        $mesmoNome = MarcaAuditoria::whereRaw('lower(descricao) = ?', [mb_strtolower($descricao)])
            ->orderByRaw('status_aprovacao is not null')
            ->first();
        if ($mesmoNome) {
            return $mesmoNome;
        }

        $marca = new MarcaAuditoria([
            'empresa_id' => $usuario->empresa_id,
            'descricao' => $descricao,
            'propriedade' => Propriedade::CONCORRENTE,
            'ativo' => true,
            'criado_por_usuario_id' => $usuario->id,
            'status_aprovacao' => StatusAprovacao::PENDENTE,
        ]);
        if ($uuid && Str::isUuid($uuid)) {
            $marca->uuid = $uuid;
        }
        $marca->save();

        return $marca;
    }

    /** Marca aceita no formulário: da lista, ou fora dela quando o formulário deixa cadastrar. */
    public static function marcaAceita(TipoRegistro $tipo, MarcaAuditoria $marca): bool
    {
        if ($tipo->marcasPredefinidas()->whereKey($marca->id)->exists()) {
            return true;
        }

        return (bool) $tipo->permite_marca_nova;
    }

    /**
     * Vínculo gravado no registro: com escopo LINHA_AUSENTE por seção/departamento, a linha do
     * escopo + a marca ("Amaciante · Downy" pela descricaoDoVinculo); senão, a marca sozinha.
     *
     * @return array{tipo_vinculo: string, secao_id: ?int, departamento_id: ?int}
     */
    public static function vinculo(TipoRegistro $tipo): array
    {
        $escopo = $tipo->escopo_tipo_vinculo;
        if ($escopo === TipoItemCampanha::SECAO && $tipo->escopo_secao_id) {
            return ['tipo_vinculo' => TipoItemCampanha::SECAO->value, 'secao_id' => $tipo->escopo_secao_id, 'departamento_id' => null];
        }
        if ($escopo === TipoItemCampanha::DEPARTAMENTO && $tipo->escopo_departamento_id) {
            return ['tipo_vinculo' => TipoItemCampanha::DEPARTAMENTO->value, 'secao_id' => null, 'departamento_id' => $tipo->escopo_departamento_id];
        }

        return ['tipo_vinculo' => TipoItemCampanha::MARCA->value, 'secao_id' => null, 'departamento_id' => null];
    }

    /** Pergunta de preço do formulário: a primeira em R$ (docs/85 §3.2, "Preço na gôndola"). */
    public static function chavePreco(TipoRegistro $tipo): ?string
    {
        return $tipo->campos->first(fn ($c) => $c->tipo_campo === TipoCampoRegistro::MOEDA)?->chave;
    }

    /** R$ por litro/kg; null sem preço, sem embalagem ou com unidade avulsa. */
    public static function precoPorReferencia(?float $preco, ?float $quantidade, ?string $unidade): ?float
    {
        $u = $unidade ? UnidadeEmbalagem::tryFrom($unidade) : null;
        $ref = ($u && $quantidade) ? $u->emReferencia($quantidade) : null;
        if ($preco === null || ! $ref) {
            return null;
        }

        return round($preco / $ref, 2);
    }

    public static function numero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        $limpo = str_replace(['R$', ' ', '.'], '', (string) $valor);
        $limpo = str_replace(',', '.', $limpo);

        return is_numeric($limpo) ? (float) $limpo : null;
    }

    /**
     * Visão da indústria (docs/85 §3.4): uma linha por marca + embalagem, com preço médio,
     * R$/litro (ordenado do mais barato), lojas e as "circunstâncias" — % de "sim" nas perguntas
     * Sim/Não e média nas numéricas.
     *
     * @param  Collection<int, VisitaRegistro>  $registros  do formulário, com `marca` e `visita` carregados
     */
    public static function resumo(TipoRegistro $tipo, Collection $registros): array
    {
        $chavePreco = self::chavePreco($tipo);
        $circunstancias = $tipo->campos->filter(fn ($c) => $c->chave !== $chavePreco
            && in_array($c->tipo_campo, [TipoCampoRegistro::BOOLEANO, TipoCampoRegistro::NUMERO], true))->values();

        $linhas = $registros
            ->filter(fn (VisitaRegistro $r) => $r->marca_id && $r->embalagem_unidade)
            ->groupBy(fn (VisitaRegistro $r) => $r->marca_id.'|'.(float) $r->embalagem_quantidade.'|'.$r->embalagem_unidade)
            ->map(function (Collection $grupo) use ($chavePreco, $circunstancias) {
                /** @var VisitaRegistro $primeiro */
                $primeiro = $grupo->first();
                $precos = $grupo->map(fn ($r) => $chavePreco ? self::numero($r->valores_campos[$chavePreco] ?? null) : null)->filter(fn ($p) => $p !== null);
                $precoMedio = $precos->isNotEmpty() ? round($precos->avg(), 2) : null;
                $unidade = UnidadeEmbalagem::tryFrom($primeiro->embalagem_unidade);

                return [
                    'marca' => [
                        'id' => $primeiro->marca?->uuid,
                        'descricao' => $primeiro->marca?->descricao,
                        'a_aprovar' => $primeiro->marca?->status_aprovacao === StatusAprovacao::PENDENTE,
                    ],
                    'embalagem' => UnidadeEmbalagem::descrever($primeiro->embalagem_quantidade, $primeiro->embalagem_unidade),
                    'embalagem_quantidade' => $primeiro->embalagem_quantidade,
                    'embalagem_unidade' => $primeiro->embalagem_unidade,
                    'registros' => $grupo->count(),
                    'lojas' => $grupo->map(fn ($r) => $r->visita?->ponto_venda_id)->filter()->unique()->count(),
                    'preco_medio' => $precoMedio,
                    'preco_min' => $precos->isNotEmpty() ? round($precos->min(), 2) : null,
                    'preco_max' => $precos->isNotEmpty() ? round($precos->max(), 2) : null,
                    'preco_por_referencia' => self::precoPorReferencia($precoMedio, $primeiro->embalagem_quantidade, $primeiro->embalagem_unidade),
                    'referencia' => $unidade?->referencia(),
                    'circunstancias' => $circunstancias->map(function ($campo) use ($grupo) {
                        $valores = $grupo->map(fn ($r) => $r->valores_campos[$campo->chave] ?? null)->filter(fn ($v) => $v !== null && $v !== '');
                        if ($valores->isEmpty()) {
                            return ['rotulo' => $campo->rotulo, 'valor' => null];
                        }
                        $valor = $campo->tipo_campo === TipoCampoRegistro::BOOLEANO
                            ? round($valores->filter(fn ($v) => in_array((string) $v, ['1', 'true'], true))->count() * 100 / $valores->count()).'% sim'
                            : (string) round($valores->map(fn ($v) => self::numero($v) ?? 0)->avg(), 1);

                        return ['rotulo' => $campo->rotulo, 'valor' => $valor];
                    })->values()->all(),
                ];
            })
            // Do mais barato por litro/kg; sem R$/litro (avulso, sem preço) vai pro fim.
            ->sortBy(fn ($l) => [$l['preco_por_referencia'] === null ? 1 : 0, $l['preco_por_referencia'] ?? 0, $l['marca']['descricao']])
            ->values();

        $comReferencia = $linhas->whereNotNull('preco_por_referencia');

        return [
            'linhas' => $linhas->all(),
            'destaques' => [
                'mais_barato' => $comReferencia->first(),
                'total_embalagens' => $linhas->count(),
                'total_marcas' => $linhas->pluck('marca.id')->unique()->count(),
                'total_registros' => $registros->count(),
            ],
        ];
    }
}
