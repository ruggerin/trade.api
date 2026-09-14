<?php

namespace App\Support;

/**
 * Normaliza o código de ícone digitado pelo gestor pra `TipoRegistro.icone` — slug do Material
 * Design Icons (pictogrammers.com/library/mdi), sem o prefixo "mdi-"/"mdi:" que o site do MDI
 * mostra junto do nome (ex.: o gestor pode digitar "mdi-camera", "mdi:camera" ou só "camera",
 * os três viram "camera"). Guardar sempre sem prefixo é o que casa direto com o nome esperado
 * por `MaterialCommunityIcons` no mobile (`@expo/vector-icons`), sem tradução nenhuma.
 */
class IconeTipoRegistro
{
    public static function normalizar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        $semPrefixo = preg_replace('/^mdi[:\-]/i', '', $valor);

        return strtolower($semPrefixo);
    }
}
