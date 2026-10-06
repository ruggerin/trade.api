<?php

namespace App\Enums;

/**
 * Documentos legais com aceite registrado — docs/58-ACEITE-TERMOS-E-PRIVACIDADE.md. O texto de
 * cada um mora em `resources/legal/{slug}.md` e vira versão no banco via
 * `php artisan documentos-legais:publicar`.
 */
enum TipoDocumentoLegal: string
{
    case TERMOS_USO = 'TERMOS_USO';
    case POLITICA_PRIVACIDADE = 'POLITICA_PRIVACIDADE';

    /** Como aparece na URL pública e no nome do arquivo em resources/legal/. */
    public function slug(): string
    {
        return match ($this) {
            self::TERMOS_USO => 'termos-de-uso',
            self::POLITICA_PRIVACIDADE => 'politica-de-privacidade',
        };
    }

    public function titulo(): string
    {
        return match ($this) {
            self::TERMOS_USO => 'Termos de Uso',
            self::POLITICA_PRIVACIDADE => 'Política de Privacidade',
        };
    }

    public function arquivo(): string
    {
        return resource_path("legal/{$this->slug()}.md");
    }

    public static function doSlug(string $slug): ?self
    {
        foreach (self::cases() as $tipo) {
            if ($tipo->slug() === $slug) {
                return $tipo;
            }
        }

        return null;
    }
}
