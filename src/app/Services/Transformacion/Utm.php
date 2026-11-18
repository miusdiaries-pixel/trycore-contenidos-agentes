<?php

namespace App\Services\Transformacion;

use App\Support\Texto;
use RuntimeException;

/** Construye los parámetros UTM según la convención del config. */
class Utm
{
    public static function params(array $config, string $canal, string $formato, int $numero, string $slug, string $aaaamm): array
    {
        $u = $config['utm'];
        if (!isset($u['utm_source'][$canal], $u['utm_medium'][$canal])) {
            throw new RuntimeException("El canal '$canal' no está en la convención UTM");
        }
        $params = [
            'utm_source' => $u['utm_source'][$canal],
            'utm_medium' => $u['utm_medium'][$canal],
            'utm_campaign' => strtr($u['utm_campaign'], ['{slug_pieza_madre}' => $slug, '{aaaamm}' => $aaaamm]),
            'utm_content' => strtr($u['utm_content'], ['{formato}' => $formato, '{numero}' => (string) $numero]),
        ];
        // Minúsculas, sin tildes ni espacios; las palabras se separan con guion.
        return array_map([Texto::class, 'slug'], $params);
    }

    public static function construir(array $config, string $canal, string $formato, int $numero, string $slug, string $aaaamm, string $urlBase): string
    {
        $base = strtok($urlBase, '?#');
        return $base . '?' . http_build_query(self::params($config, $canal, $formato, $numero, $slug, $aaaamm), '', '&', PHP_QUERY_RFC3986);
    }
}
