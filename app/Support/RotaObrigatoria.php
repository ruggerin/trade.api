<?php

namespace App\Support;

use App\Enums\Permissao;
use App\Enums\StatusOrdemServico;
use App\Enums\UserType;
use App\Models\OrdemServico;
use App\Models\Usuario;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Iniciar visita só pela agenda e seguir a sequência do dia (docs/74). As duas regras moram no
 * Perfil como marcas de restrição: quem tem a marca é obrigado, sem a marca nada muda. ADMIN nunca
 * é restringido (mesma decisão do doc 64: não trancar a empresa pra fora).
 *
 * A ordem do dia é a mesma que o app mostra na Agenda (mobile/src/lib/rotaObrigatoria.ts): o
 * horário previsto no dia, senão o fim do prazo, e o uuid como desempate estável.
 */
final class RotaObrigatoria
{
    private const NA_FILA = [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO];

    public static function exigeAgenda(Usuario $usuario): bool
    {
        return self::tem($usuario, Permissao::VISITAS_EXIGIR_AGENDA);
    }

    public static function exigeSequencia(Usuario $usuario): bool
    {
        // A API do perfil não deixa salvar sequência sem agenda; aqui só reforça.
        return self::exigeAgenda($usuario) && self::tem($usuario, Permissao::VISITAS_EXIGIR_SEQUENCIA);
    }

    /**
     * Compromissos de hoje do usuário que ainda contam pra sequência, já na ordem de atendimento.
     * Pendência de outro dia não entra (uma OS esquecida não trava o dia inteiro); concluída,
     * cancelada ou reagendada pra outro dia sai da fila e libera a seguinte.
     *
     * @return Collection<int, OrdemServico>
     */
    public static function filaDoDia(Usuario $usuario, CarbonInterface $instante): Collection
    {
        $fuso = Fuso::daEmpresa($usuario->empresa);
        $dia = $instante->copy()->setTimezone($fuso)->toDateString();
        [$inicio, $fim] = Fuso::intervaloDoDia($dia, $fuso);

        return OrdemServico::query()
            ->with('pontoVenda:id,uuid,fantasia')
            ->where('usuario_id', $usuario->id)
            ->whereIn('status', self::NA_FILA)
            ->whereBetween('prazo_fim', [$inicio, $fim])
            ->get()
            ->sortBy(fn (OrdemServico $os) => [self::alvo($os, $dia, $fuso)->getTimestamp(), $os->uuid])
            ->values();
    }

    /** A OS que vem antes desta na fila de hoje, se houver — null quando é a da vez (ou está fora da fila). */
    public static function antesDaVez(OrdemServico $escolhida, Usuario $usuario, CarbonInterface $instante): ?OrdemServico
    {
        $fila = self::filaDoDia($usuario, $instante);
        $posicao = $fila->search(fn (OrdemServico $os) => $os->id === $escolhida->id);

        return $posicao === false || $posicao === 0 ? null : $fila->first();
    }

    /** Horário de atendimento: o previsto, no dia da fila; sem ele, o fim do prazo. */
    private static function alvo(OrdemServico $os, string $dia, string $fuso): Carbon
    {
        return $os->horario_previsto
            ? Fuso::instanteLocal($dia, substr((string) $os->horario_previsto, 0, 5), $fuso)
            : $os->prazo_fim->copy()->utc();
    }

    private static function tem(Usuario $usuario, Permissao $permissao): bool
    {
        if ($usuario->user_type === UserType::ADMIN) {
            return false;
        }

        return $usuario->perfil?->tem($permissao) ?? false;
    }
}
