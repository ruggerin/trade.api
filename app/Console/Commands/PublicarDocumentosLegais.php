<?php

namespace App\Console\Commands;

use App\Enums\TipoDocumentoLegal;
use App\Support\DocumentosLegais;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Publica os textos de resources/legal/ como versões no banco — docs/58 §5.1. Rodar depois do
 * deploy sempre que um texto mudar; textos iguais ao já publicado são ignorados.
 */
class PublicarDocumentosLegais extends Command
{
    protected $signature = 'documentos-legais:publicar {--dry-run : Só mostra o que faria, sem gravar}';

    protected $description = 'Publica as versões novas dos Termos de Uso e da Política de Privacidade (resources/legal/)';

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');
        $falhou = false;

        foreach (TipoDocumentoLegal::cases() as $tipo) {
            $arquivo = $tipo->arquivo();
            if (! is_file($arquivo)) {
                $this->error("{$tipo->titulo()}: arquivo não encontrado ({$arquivo}).");
                $falhou = true;

                continue;
            }

            try {
                $resultado = DocumentosLegais::publicar($tipo, (string) file_get_contents($arquivo), $simular);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                $falhou = true;

                continue;
            }

            $mensagem = match ($resultado['status']) {
                'publicado' => $simular ? 'seria publicada' : 'publicada',
                'sem_mudanca' => 'já publicada, sem mudança',
            };
            $this->line("{$tipo->titulo()} {$resultado['versao']}: {$mensagem}.");
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
