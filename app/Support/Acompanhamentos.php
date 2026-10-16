<?php

namespace App\Support;

use App\Enums\TipoCampoRegistro;
use App\Models\AcompanhamentoRegistro;
use App\Models\AcompanhamentoResposta;
use App\Models\Empresa;
use App\Models\MotivoResolucaoAlerta;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Ainda está lá?" (docs/83). Abre o acompanhamento quando um registro do formulário satisfaz o
 * gatilho, aplica as respostas das próximas visitas e encerra por prazo, cancelamento ou gestor.
 *
 * Datas de verificação em dias de calendário no fuso da empresa, igual ao intervalo do doc 80:
 * próxima verificação = dia da última resposta (ou do registro) + N. Com N = 0, toda visita.
 */
final class Acompanhamentos
{
    public const AO_CONTINUAR = ['CONFIRMA', 'FORMULARIO_PREENCHIDO', 'FORMULARIO_CEGO'];

    /** Motivo de resolução automática do alerta (docs/83 §7.3) — criado na primeira vez, por empresa. */
    public const MOTIVO_CONFIRMADO_NA_LOJA = 'Confirmado na loja';

    /** Depois de gravar um registro: abre (e substitui o anterior do mesmo item), se o formulário pede. */
    public static function abrirSeAplica(VisitaRegistro $registro): ?AcompanhamentoRegistro
    {
        // Registro de "Continua" ou de justificativa não abre cadeia nova (docs/83 §5.1).
        if ($registro->acompanhamento_id !== null) {
            return null;
        }

        $tipo = TipoRegistro::withoutGlobalScopes()->find($registro->tipo_registro_id);
        if (! $tipo?->acompanha || ! self::gatilhoSatisfeito($tipo, $registro->valores_campos ?? [])) {
            return null;
        }

        $visita = $registro->visita ?? Visita::withoutGlobalScopes()->find($registro->visita_id);
        $empresa = Empresa::find($tipo->empresa_id);
        $fuso = Fuso::daEmpresa($empresa);
        $criadoEm = Carbon::parse($registro->created_at);

        $chaveItem = [
            'produto_auditoria_id' => $registro->produto_auditoria_id,
            'tipo_vinculo' => self::valor($registro->tipo_vinculo),
            'secao_id' => $registro->secao_id,
            'departamento_id' => $registro->departamento_id,
            'marca_id' => $registro->marca_id,
        ];

        return DB::transaction(function () use ($tipo, $visita, $registro, $criadoEm, $fuso, $chaveItem) {
            // Registro novo do mesmo item na loja substitui o antigo (docs/83 §3.3.1).
            $abertos = AcompanhamentoRegistro::withoutGlobalScopes()
                ->where('tipo_registro_id', $tipo->id)
                ->where('ponto_venda_id', $visita->ponto_venda_id)
                ->where('situacao', AcompanhamentoRegistro::ABERTO);
            foreach ($chaveItem as $coluna => $valor) {
                $valor === null ? $abertos->whereNull($coluna) : $abertos->where($coluna, $valor);
            }
            $abertos->update(['situacao' => AcompanhamentoRegistro::SUBSTITUIDO, 'encerrado_em' => now(), 'updated_at' => now()]);

            return AcompanhamentoRegistro::create($chaveItem + [
                'empresa_id' => $tipo->empresa_id,
                'tipo_registro_id' => $tipo->id,
                'ponto_venda_id' => $visita->ponto_venda_id,
                'registro_origem_id' => $registro->id,
                'situacao' => AcompanhamentoRegistro::ABERTO,
                'proxima_verificacao_em' => self::proximaVerificacao($criadoEm, $tipo->acompanha_intervalo_dias, $fuso),
                'expira_em' => $tipo->acompanha_prazo_dias !== null
                    ? $criadoEm->copy()->setTimezone($fuso)->startOfDay()->addDays($tipo->acompanha_prazo_dias)->toDateString()
                    : null,
            ]);
        });
    }

    /** Sem pergunta de gatilho, todo registro; com ela, só a resposta configurada. */
    public static function gatilhoSatisfeito(TipoRegistro $tipo, array $valores): bool
    {
        if ($tipo->acompanha_gatilho_chave === null || $tipo->acompanha_gatilho_chave === '') {
            return true;
        }

        return (string) ($valores[$tipo->acompanha_gatilho_chave] ?? '') === (string) $tipo->acompanha_gatilho_valor;
    }

    public static function proximaVerificacao(CarbonInterface $base, int $dias, string $fuso): string
    {
        return Carbon::parse($base)->setTimezone($fuso)->startOfDay()->addDays(max(0, $dias))->toDateString();
    }

    /**
     * Aplica uma resposta. Idempotente pela `idempotency_key` (reenvio da fila offline). Se o
     * acompanhamento já não está aberto (dois promotores offline), a resposta fica só no
     * histórico: vale a primeira que chega (docs/83 §7.4).
     */
    public static function responder(
        AcompanhamentoRegistro $acompanhamento,
        Visita $visita,
        Usuario $usuario,
        string $resposta,
        CarbonInterface $respondidoEm,
        ?VisitaRegistro $registro,
        string $idempotencyKey,
    ): AcompanhamentoResposta {
        return DB::transaction(function () use ($acompanhamento, $visita, $usuario, $resposta, $respondidoEm, $registro, $idempotencyKey) {
            $acompanhamento = AcompanhamentoRegistro::withoutGlobalScopes()->lockForUpdate()->findOrFail($acompanhamento->id);

            $existente = AcompanhamentoResposta::where('acompanhamento_id', $acompanhamento->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existente) {
                return $existente;
            }

            $aberto = $acompanhamento->situacao === AcompanhamentoRegistro::ABERTO;
            $registrada = AcompanhamentoResposta::create([
                'acompanhamento_id' => $acompanhamento->id,
                'visita_id' => $visita->id,
                'usuario_id' => $usuario->id,
                'resposta' => $resposta,
                'registro_id' => $registro?->id,
                'respondido_em' => $respondidoEm,
                'aplicada' => $aberto,
                'idempotency_key' => $idempotencyKey,
            ]);

            if (! $aberto) {
                return $registrada;
            }

            $tipo = TipoRegistro::withoutGlobalScopes()->find($acompanhamento->tipo_registro_id);
            $fuso = Fuso::daEmpresa(Empresa::find($acompanhamento->empresa_id));

            if ($resposta === AcompanhamentoResposta::CONTINUA) {
                $acompanhamento->update([
                    'ultima_resposta_em' => $respondidoEm,
                    'proxima_verificacao_em' => self::proximaVerificacao($respondidoEm, $tipo?->acompanha_intervalo_dias ?? 0, $fuso),
                ]);
            } elseif ($resposta === AcompanhamentoResposta::ACABOU) {
                $acompanhamento->update([
                    'ultima_resposta_em' => $respondidoEm,
                    'situacao' => AcompanhamentoRegistro::ENCERRADO,
                    'encerrado_em' => $respondidoEm,
                    'encerrado_por_id' => $usuario->id,
                ]);
                self::resolverAlertaDaOrigem($acompanhamento, $usuario, $respondidoEm);
            }
            // NAO_CONSEGUI_VER: conta como respondido nesta visita e volta na próxima — datas intactas.

            return $registrada;
        });
    }

    /** Ruptura (ou qualquer alerta) resolvida na loja: "Acabou" fecha o alerta do registro original (docs/83 §3.4). */
    private static function resolverAlertaDaOrigem(AcompanhamentoRegistro $acompanhamento, Usuario $usuario, CarbonInterface $quando): void
    {
        $origem = VisitaRegistro::withoutGlobalScopes()->with('tipoRegistro')->find($acompanhamento->registro_origem_id);
        if (! $origem || ! $origem->tipoRegistro?->eh_alerta || $origem->alerta_resolvido_em !== null) {
            return;
        }

        $motivo = MotivoResolucaoAlerta::withoutGlobalScopes()->firstOrCreate(
            ['empresa_id' => $acompanhamento->empresa_id, 'descricao' => self::MOTIVO_CONFIRMADO_NA_LOJA],
            ['ativo' => true],
        );
        $origem->update([
            'alerta_resolvido_em' => $quando,
            'alerta_resolvido_por_id' => $usuario->id,
            'alerta_motivo_id' => $motivo->id,
        ]);
    }

    public static function encerrarPeloGestor(AcompanhamentoRegistro $acompanhamento, Usuario $gestor): void
    {
        if ($acompanhamento->situacao !== AcompanhamentoRegistro::ABERTO) {
            return;
        }
        $acompanhamento->update([
            'situacao' => AcompanhamentoRegistro::ENCERRADO_PELO_GESTOR,
            'encerrado_em' => now(),
            'encerrado_por_id' => $gestor->id,
        ]);
    }

    /** Registro original cancelado: o acompanhamento some pra todo mundo (docs/83 §6). */
    public static function cancelarDoRegistro(VisitaRegistro $registro): void
    {
        AcompanhamentoRegistro::withoutGlobalScopes()
            ->where('registro_origem_id', $registro->id)
            ->where('situacao', AcompanhamentoRegistro::ABERTO)
            ->update(['situacao' => AcompanhamentoRegistro::CANCELADO, 'encerrado_em' => now(), 'updated_at' => now()]);
    }

    /** Comando diário: o que passou do prazo vira EXPIRADO. @return int quantos expiraram */
    public static function expirarVencidos(): int
    {
        $total = 0;
        Empresa::query()->each(function (Empresa $empresa) use (&$total) {
            $hoje = Fuso::hoje(Fuso::daEmpresa($empresa))->toDateString();
            $total += AcompanhamentoRegistro::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('situacao', AcompanhamentoRegistro::ABERTO)
                ->whereNotNull('expira_em')
                ->where('expira_em', '<', $hoje)
                ->update(['situacao' => AcompanhamentoRegistro::EXPIRADO, 'encerrado_em' => now(), 'updated_at' => now()]);
        });

        return $total;
    }

    /**
     * Acompanhamentos abertos da loja pro app (carga offline do detalhe do PDV). Formulário
     * desativado ou com o acompanhamento desligado fica de fora — "congelado" (docs/83 §7.5).
     *
     * @return list<array<string, mixed>>
     */
    public static function abertosDaLoja(PontoVenda $pontoVenda): array
    {
        $hoje = Fuso::hoje(Fuso::daLoja($pontoVenda))->toDateString();

        return AcompanhamentoRegistro::query()
            ->where('ponto_venda_id', $pontoVenda->id)
            ->where('situacao', AcompanhamentoRegistro::ABERTO)
            ->where(fn ($q) => $q->whereNull('expira_em')->orWhere('expira_em', '>=', $hoje))
            ->whereHas('tipoRegistro', fn ($q) => $q->where('ativo', true)->where('acompanha', true))
            ->with([
                'tipoRegistro.campos', 'tipoRegistro.formularioAcabou:id,uuid,descricao',
                'registroOrigem.visita:id,uuid,usuario_id', 'registroOrigem.visita.usuario:id,nome',
                'registroOrigem.imagens', 'registroOrigem.produtoAuditoria:id,uuid,descricao',
                'registroOrigem.secao:id,uuid,descricao', 'registroOrigem.departamento:id,uuid,descricao', 'registroOrigem.marca:id,uuid,descricao',
            ])
            ->orderBy('proxima_verificacao_em')
            ->get()
            ->map(fn (AcompanhamentoRegistro $a) => self::paraApp($a))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function paraApp(AcompanhamentoRegistro $a): array
    {
        $tipo = $a->tipoRegistro;
        $origem = $a->registroOrigem;
        $cego = $tipo->acompanha_ao_continuar === 'FORMULARIO_CEGO';
        $imagem = $origem?->imagens->first();

        $resumo = $cego ? [] : collect(FormatadorValoresCampos::formatar($tipo, $origem?->valores_campos))
            ->filter(fn ($l) => $l['tipo_campo'] !== TipoCampoRegistro::SORTIMENTO->value)
            ->take(4)
            ->map(fn ($l) => ['rotulo' => $l['rotulo'], 'valor' => (string) $l['valor']])
            ->values()
            ->all();

        $vinculo = $origem?->secao?->descricao ?? $origem?->departamento?->descricao;
        if ($vinculo && $origem?->marca) {
            $vinculo .= ' · '.$origem->marca->descricao;
        }

        return [
            'id' => $a->uuid,
            'tipo_registro' => ['id' => $tipo->uuid, 'descricao' => $tipo->descricao],
            'proxima_verificacao_em' => $a->proxima_verificacao_em?->toDateString(),
            'ultima_resposta_em' => $a->ultima_resposta_em?->toIso8601String(),
            'expira_em' => $a->expira_em?->toDateString(),
            'rotulo_continua' => $tipo->acompanha_rotulo_continua ?: 'Continua',
            'rotulo_acabou' => $tipo->acompanha_rotulo_acabou ?: 'Acabou',
            'ao_continuar' => $tipo->acompanha_ao_continuar,
            'formulario_acabou' => $tipo->formularioAcabou ? ['id' => $tipo->formularioAcabou->uuid, 'descricao' => $tipo->formularioAcabou->descricao] : null,
            'origem' => [
                'id' => $origem?->uuid,
                'criado_em' => $origem?->created_at?->toIso8601String(),
                'usuario_nome' => $origem?->visita?->usuario?->nome,
                'miniatura_url' => $imagem && $origem?->visita ? url("/api/visitas/{$origem->visita->uuid}/imagens/{$imagem->uuid}") : null,
                'produto' => $origem?->produtoAuditoria ? ['id' => $origem->produtoAuditoria->uuid, 'descricao' => $origem->produtoAuditoria->descricao] : null,
                'tipo_vinculo' => self::valor($origem?->tipo_vinculo),
                'secao_uuid' => $origem?->secao?->uuid,
                'departamento_uuid' => $origem?->departamento?->uuid,
                'marca_uuid' => $origem?->marca?->uuid,
                'vinculo_descricao' => $vinculo ?? $origem?->marca?->descricao,
                'resumo' => $resumo,
                // Modo "preenchido": o app abre o formulário com a última coleta (docs/83 §5.4).
                'valores_campos' => $tipo->acompanha_ao_continuar === 'FORMULARIO_PREENCHIDO' ? ($a->ultimaColeta() ?? $origem?->valores_campos) : null,
            ],
        ];
    }

    private static function valor(mixed $v): ?string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : ($v === null ? null : (string) $v);
    }
}
