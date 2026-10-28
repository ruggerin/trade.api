<?php

namespace App\Http\Resources;

use App\Models\Integracao;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Integração pra tela do admin (docs/96 §8). Nunca expõe o segredo: dos tokens sai só o prefixo
 * visível e a validade. `situacao` resume o que a lista destaca: ativa, vencendo (≤ 15 dias),
 * vencida (nenhum token valendo), pausada ou revogada.
 *
 * @mixin Integracao
 */
class IntegracaoResource extends JsonResource
{
    public const DIAS_AVISO_VENCIMENTO = 15;

    public function toArray(Request $request): array
    {
        $tokens = $this->relationLoaded('tokens') ? $this->tokens : $this->tokens()->get();
        $validos = $tokens->filter(fn (PersonalAccessToken $t) => $t->revogado_em === null && ($t->expires_at === null || $t->expires_at->isFuture()));
        $validade = $validos->isEmpty() || $validos->contains(fn ($t) => $t->expires_at === null)
            ? null
            : $validos->max('expires_at');

        return [
            'uuid' => $this->uuid,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'escopos' => $this->escopos,
            'ips_permitidos' => $this->ips_permitidos ?? [],
            'limite_por_minuto' => $this->limite_por_minuto,
            'situacao' => $this->situacao($validos->isEmpty(), $validade),
            'validade' => $validade?->toIso8601String(),
            'criada_por' => $this->criadaPor?->nome,
            'created_at' => $this->created_at?->toIso8601String(),
            'revogada_em' => $this->revogada_em?->toIso8601String(),
            'revogada_por' => $this->revogadaPor?->nome,
            'revogada_motivo' => $this->revogada_motivo,
            'ultimo_uso_em' => $this->ultimo_uso_em?->toIso8601String(),
            'ultimo_ip' => $this->ultimo_ip,
            'tokens' => $validos->sortByDesc('id')->values()->map(fn (PersonalAccessToken $t, int $i) => [
                'prefixo' => $t->prefixo_visivel.'…',
                'expira_em' => $t->expires_at?->toIso8601String(),
                // O mais novo é o atual; os outros ainda valendo são os antigos de uma troca.
                'antigo' => $i > 0,
            ]),
            $this->mergeWhen(isset($this->uso_hoje), fn () => ['uso_hoje' => $this->uso_hoje]),
        ];
    }

    private function situacao(bool $semTokenValido, ?\Carbon\CarbonInterface $validade): string
    {
        return match (true) {
            $this->revogada_em !== null => 'revogada',
            ! $this->ativa => 'pausada',
            $semTokenValido => 'vencida',
            $validade !== null && $validade->lt(now()->addDays(self::DIAS_AVISO_VENCIMENTO)) => 'vencendo',
            default => 'ativa',
        };
    }
}
