<?php

namespace App\Support;

class Texto
{
    private const MAPA = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];

    /** Minúsculas y sin tildes, para comparar sin que importe cómo se escribió. */
    public static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto), self::MAPA);
    }

    public static function slug(string $texto): string
    {
        $t = preg_replace('/[^a-z0-9]+/', '-', self::normalizar($texto));
        return trim($t, '-');
    }

    public static function limpiar(string $texto): string
    {
        $texto = str_replace("\xC2\xA0", ' ', $texto);
        return trim(preg_replace('/\s+/u', ' ', $texto));
    }

    public static function palabras(string $texto): int
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $texto);
    }

    /** @return string[] */
    public static function frases(string $texto): array
    {
        $partes = preg_split('/(?<=[.!?…])\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
        return $partes ?: [];
    }

    /**
     * ¿Aparece el término como palabra o frase completa, sin importar tildes?
     * Tampoco importan las mayúsculas, salvo en términos de una sola palabra escritos
     * con inicial mayúscula (como «Llama»), para no confundir el nombre de un modelo
     * con el verbo «llama».
     */
    public static function contiene(string $texto, string $termino): bool
    {
        if (preg_match('/^\p{Lu}\p{Ll}+$/u', $termino)) {
            $sinTildes = fn (string $s) => strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
            $t = preg_quote($sinTildes($termino), '/');
            return (bool) preg_match('/(?<![\p{L}\p{N}])' . $t . '(?![\p{L}\p{N}])/u', $sinTildes($texto));
        }
        $t = preg_quote(self::normalizar($termino), '/');
        return (bool) preg_match('/(?<![a-z0-9])' . $t . '(?![a-z0-9])/u', self::normalizar($texto));
    }

    /** Fragmento del texto alrededor de la primera aparición del término. */
    public static function contexto(string $texto, string $termino, int $radio = 70): ?string
    {
        $n = self::normalizar($texto);
        $pos = mb_strpos($n, self::normalizar($termino));
        if ($pos === false) {
            return null;
        }
        return '…' . trim(mb_substr($texto, max(0, $pos - $radio), $radio * 2 + mb_strlen($termino))) . '…';
    }
}
