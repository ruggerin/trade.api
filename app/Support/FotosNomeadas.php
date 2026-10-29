<?php

namespace App\Support;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\ImagemRegistro;
use App\Models\Parametro;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fotos nomeadas no formulário e galeria Horus (docs/98).
 *
 * - A pergunta FOTO guarda as fotos na pivô `visita_registro_imagem` com `campo_chave`.
 * - "Usar foto já tirada" só oferece fotos tiradas pela câmera do app: desta visita ou de visitas
 *   anteriores na mesma loja, dentro de `FOTOS_REAPROVEITAR_DIAS` (padrão 60).
 * - Cada foto guarda hora e GPS da captura; daí saem os alertas "fora da loja" e "fora de ordem".
 */
final class FotosNomeadas
{
    public const ORIGENS = ['CAMERA', 'VISITA', 'HISTORICO'];

    public const REAPROVEITAR = ['NAO', 'VISITA', 'HISTORICO'];

    public const DIAS_PADRAO = 60;

    /** Teto da lista "visitas anteriores": o promotor rola isso no celular, com rede móvel. */
    public const LIMITE_HISTORICO = 60;

    public static function diasHistorico(Empresa $empresa): int
    {
        $valor = Parametro::query()->withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'FOTOS_REAPROVEITAR_DIAS')
            ->where('ativo', true)
            ->value('valor');

        return is_numeric($valor) && (int) $valor > 0 ? min(365, (int) $valor) : self::DIAS_PADRAO;
    }

    /**
     * Fotos de visitas anteriores na mesma loja que podem virar o "Antes" desta visita: de qualquer
     * promotor da empresa (a loja troca de promotor), de visita não cancelada, ligadas a pelo menos
     * um registro não cancelado, dentro da janela.
     */
    public static function historicoDaLoja(Visita $visita): Builder
    {
        $empresa = $visita->empresa()->withoutGlobalScopes()->first();
        $desde = now()->subDays(self::diasHistorico($empresa));

        return ImagemRegistro::query()
            ->whereHas('visita', fn (Builder $q) => $q->withoutGlobalScopes()
                ->where('empresa_id', $visita->empresa_id)
                ->where('ponto_venda_id', $visita->ponto_venda_id)
                ->whereKeyNot($visita->id)
                ->where('status', '!=', StatusVisita::CANCELADA))
            ->whereHas('registros', fn (Builder $q) => $q->whereNull('cancelado_em'))
            ->where(fn (Builder $q) => $q->where('capturada_em', '>=', $desde)
                ->orWhere(fn (Builder $q) => $q->whereNull('capturada_em')->where('created_at', '>=', $desde)));
    }

    /**
     * "Na loja" pras fotos = o raio do check-in da empresa (sem limite = nunca alerta). Guardado
     * nos atributos do request: uma lista de registros consulta o parâmetro uma vez só.
     */
    public static function raioDaVisita(Visita $visita): float
    {
        $atributos = request()->attributes;
        $chave = 'fotos_nomeadas.raio.'.$visita->empresa_id;
        if (! $atributos->has($chave)) {
            $atributos->set($chave, RaioCheckin::metros($visita->empresa()->withoutGlobalScopes()->first()));
        }

        return $atributos->get($chave);
    }

    /** Distância da foto até a loja da visita (null sem GPS). */
    public static function distanciaDaLoja(Visita $visita, ?float $latitude, ?float $longitude): ?int
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }
        $visita->loadMissing('pontoVenda');
        if ($visita->pontoVenda?->latitude === null || $visita->pontoVenda?->longitude === null) {
            return null;
        }

        return (int) round(FinalizacaoVisita::distanciaDaLoja($visita, $latitude, $longitude));
    }

    /**
     * Alertas das fotos de um registro (docs/98 §5): foto tirada longe da loja e pergunta FOTO
     * posterior (o "Depois") com foto mais antiga que a da pergunta anterior (o "Antes"). Fotos
     * reaproveitadas de visita anterior ficam fora da comparação de ordem: o "Antes" de outra
     * visita é antigo por definição.
     *
     * @return array<int, string>
     */
    public static function alertas(VisitaRegistro $registro, float $raioMetros): array
    {
        $alertas = [];
        $imagens = $registro->imagens;

        $longe = $imagens->filter(fn (ImagemRegistro $i) => $i->pivot->origem !== 'HISTORICO'
            && $i->distancia_loja_metros !== null && $i->distancia_loja_metros > $raioMetros);
        if ($longe->isNotEmpty()) {
            $alertas[] = $longe->count() === 1 ? 'Uma foto foi tirada fora da loja.' : "{$longe->count()} fotos foram tiradas fora da loja.";
        }

        $ordem = $registro->tipoRegistro?->campos?->pluck('ordem', 'chave') ?? collect();
        $porCampo = $imagens
            ->filter(fn (ImagemRegistro $i) => $i->pivot->campo_chave !== null && $i->pivot->origem !== 'HISTORICO' && $i->capturada_em !== null)
            ->groupBy(fn (ImagemRegistro $i) => $i->pivot->campo_chave)
            ->sortBy(fn (Collection $fotos, string $chave) => $ordem[$chave] ?? PHP_INT_MAX);

        $ultimaAnterior = null;
        foreach ($porCampo as $fotos) {
            $primeira = $fotos->min('capturada_em');
            if ($ultimaAnterior !== null && $primeira < $ultimaAnterior) {
                $alertas[] = 'As fotos estão fora de ordem: uma foto de uma pergunta posterior foi tirada antes da anterior.';
                break;
            }
            $ultimaAnterior = $fotos->max('capturada_em');
        }

        return $alertas;
    }
}
