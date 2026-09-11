<?php

namespace App\Support;

use App\Enums\Permissao;
use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modo de visibilidade de PontoVenda pro user_type PROMOTOR, configurável por empresa via
 * `Parametro` `PONTOS_VENDA_RESTRITO_A_VINCULO` — mesmo padrão de App\Support\RaioCheckin.
 * Ausente/inativo = modo aberto (default, comportamento original: PDV sem ninguém vinculado
 * aparece pra todo mundo). Ativo com valor truthy = modo restrito (só quem tem vínculo
 * permanente, vínculo temporário via OrdemServico, ou a permissão
 * `pontos_venda.visualizar_todos`). Ver docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md.
 */
class VisibilidadePontosVenda
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function restritaAVinculo(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'PONTOS_VENDA_RESTRITO_A_VINCULO')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }

    /**
     * Mesma regra usada por PontoVendaController::index, extraída aqui pra também validar um
     * único PDV (ver visivelParaPromotor) — ex. quando o promotor escolhe o alvo de um
     * "+ Compromisso" self-agendado (docs/13-AGENDA-MOBILE-E-AUTONOMIA.md), o backend precisa
     * confirmar que aquele PDV é um dos que ele realmente enxerga, não só confiar no app.
     */
    public static function aplicarEscopoPromotor(Builder $query, Usuario $usuario): void
    {
        if ($usuario->perfil?->tem(Permissao::PONTOS_VENDA_VISUALIZAR_TODOS) ?? false) {
            return;
        }

        $restrito = self::restritaAVinculo($usuario->empresa);

        $query->where(function ($query) use ($usuario, $restrito) {
            $query->whereHas('promotores', fn ($q) => $q->where('usuarios.id', $usuario->id));

            if ($restrito) {
                $query->orWhereHas(
                    'ordensServico',
                    fn ($q) => $q->where('usuario_id', $usuario->id)
                        ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO]),
                );
            } else {
                $query->orDoesntHave('promotores');
            }
        });
    }

    public static function visivelParaPromotor(int $pontoVendaId, Usuario $usuario): bool
    {
        $query = PontoVenda::withoutGlobalScopes()->where('id', $pontoVendaId);
        self::aplicarEscopoPromotor($query, $usuario);

        return $query->exists();
    }
}
