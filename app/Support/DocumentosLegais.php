<?php

namespace App\Support;

use App\Enums\TipoDocumentoLegal;
use App\Enums\UserType;
use App\Models\AceiteDocumentoLegal;
use App\Models\DocumentoLegal;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aceite dos Termos de Uso e da Política de Privacidade — docs/58-ACEITE-TERMOS-E-PRIVACIDADE.md.
 * Concentra: o que está pendente pra um usuário, o registro do aceite e a publicação de versões.
 */
final class DocumentosLegais
{
    private const MESES = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /**
     * Versões vigentes que o usuário ainda não aceitou (§5.3). SUPERADMIN é equipe interna, não
     * usuário de cliente — nunca tem pendência.
     *
     * @return list<array{tipo: string, versao: string}>
     */
    public static function pendentes(Usuario $usuario): array
    {
        if ($usuario->user_type === UserType::SUPERADMIN) {
            return [];
        }

        $pendentes = [];
        foreach (TipoDocumentoLegal::cases() as $tipo) {
            $vigente = DocumentoLegal::vigente($tipo);
            if ($vigente === null) {
                continue;
            }

            $aceito = AceiteDocumentoLegal::query()
                ->where('usuario_id', $usuario->id)
                ->where('documento_legal_id', $vigente->id)
                ->exists();

            if (! $aceito) {
                $pendentes[] = ['tipo' => $tipo->value, 'versao' => $vigente->versao];
            }
        }

        return $pendentes;
    }

    /**
     * Confere que cada versão enviada é a vigente do tipo. Devolve as versões vigentes
     * correspondentes, ou null se alguma estiver desatualizada (publicaram versão nova enquanto
     * a tela estava aberta) — aí ninguém "aceita" um texto que não viu (§5.4).
     *
     * @param  list<array{tipo: string, versao: string}>  $documentos
     * @return list<DocumentoLegal>|null
     */
    public static function vigentesConferidos(array $documentos): ?array
    {
        $vigentes = [];
        foreach ($documentos as $documento) {
            $vigente = DocumentoLegal::vigente(TipoDocumentoLegal::from($documento['tipo']));
            if ($vigente === null || $vigente->versao !== $documento['versao']) {
                return null;
            }
            $vigentes[$vigente->id] = $vigente;
        }

        return array_values($vigentes);
    }

    /**
     * Grava uma linha por documento. Idempotente: aceitar de novo a mesma versão não duplica nem
     * altera o aceite original.
     *
     * @param  list<DocumentoLegal>  $documentos
     */
    public static function registrarAceite(Usuario $usuario, array $documentos, Request $request, ?string $dispositivo = null): void
    {
        $app = Adesao::appDaRequisicao($request, $usuario);

        foreach ($documentos as $documento) {
            AceiteDocumentoLegal::query()->firstOrCreate(
                ['usuario_id' => $usuario->id, 'documento_legal_id' => $documento->id],
                [
                    'aceito_em' => now(),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                    'app' => $app,
                    'dispositivo_identificador' => $dispositivo ?? $usuario->dispositivo?->identificador,
                ],
            );
        }
    }

    /**
     * Versão do texto = a data da linha "**Última atualização:** 06 de outubro de 2026" → "2026-10-06".
     */
    public static function versaoDoTexto(string $conteudo): string
    {
        if (! preg_match('/\*\*Última atualização:\*\*\s*(\d{1,2})\s+de\s+([[:alpha:]çÇ]+)\s+de\s+(\d{4})/u', $conteudo, $m)) {
            throw new RuntimeException('Linha "**Última atualização:** dd de mês de aaaa" não encontrada no texto.');
        }

        $mes = self::MESES[mb_strtolower($m[2])] ?? null;
        if ($mes === null) {
            throw new RuntimeException("Mês \"{$m[2]}\" não reconhecido na data de última atualização.");
        }

        return sprintf('%04d-%02d-%02d', (int) $m[3], $mes, (int) $m[1]);
    }

    /**
     * Publica o texto como versão nova (§5.1). Devolve 'publicado', 'sem_mudanca' ou lança erro se
     * a mesma versão (data) já existe com outro texto — mudou o texto sem mudar a data.
     *
     * @return array{status: 'publicado'|'sem_mudanca', versao: string}
     */
    public static function publicar(TipoDocumentoLegal $tipo, string $conteudo, bool $simular = false): array
    {
        $versao = self::versaoDoTexto($conteudo);
        $hash = hash('sha256', $conteudo);

        $existente = DocumentoLegal::query()->where('tipo', $tipo)->where('versao', $versao)->first();

        if ($existente !== null) {
            if ($existente->hash_sha256 === $hash) {
                return ['status' => 'sem_mudanca', 'versao' => $versao];
            }

            throw new RuntimeException(
                "{$tipo->titulo()}: a versão {$versao} já foi publicada com outro texto. "
                .'Se a mudança é relevante, atualize a data de "Última atualização"; se não é, desfaça a edição.'
            );
        }

        if (! $simular) {
            DB::transaction(fn () => DocumentoLegal::create([
                'tipo' => $tipo,
                'versao' => $versao,
                'conteudo' => $conteudo,
                'hash_sha256' => $hash,
                'publicado_em' => now(),
            ]));
        }

        return ['status' => 'publicado', 'versao' => $versao];
    }
}
