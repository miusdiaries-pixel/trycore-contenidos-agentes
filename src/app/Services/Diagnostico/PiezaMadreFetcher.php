<?php

namespace App\Services\Diagnostico;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Obtiene el HTML de la pieza madre desde su URL. Si la descarga falla, usa la copia local
 * de respaldo, pero solo si la URL pedida es la misma a la que pertenece esa copia, y lo avisa.
 */
class PiezaMadreFetcher
{
    private const MINIMO_CARACTERES = 5000;

    /** @return array{url: string, origen: string, html: string, avisos: string[]} */
    public function obtener(string $url, ?string $rutaRespaldo = null, ?string $urlRespaldo = null): array
    {
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException("La URL no es válida (debe empezar por http o https): $url");
        }

        $avisos = [];
        try {
            $resp = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ContenidosBot/1.0)', 'Accept-Language' => 'es'])
                ->get($url);
            if ($resp->successful() && mb_strlen($resp->body()) > self::MINIMO_CARACTERES) {
                return ['url' => $url, 'origen' => 'web', 'html' => $resp->body(), 'avisos' => []];
            }
            $avisos[] = "La descarga respondió HTTP {$resp->status()} o con contenido insuficiente";
        } catch (\Throwable $e) {
            $avisos[] = 'No se pudo descargar la URL: ' . $e->getMessage();
        }

        if ($rutaRespaldo && is_file($rutaRespaldo) && $urlRespaldo && $this->mismaUrl($url, $urlRespaldo)) {
            $avisos[] = 'Se usó la copia local de respaldo';
            return ['url' => $url, 'origen' => 'respaldo', 'html' => file_get_contents($rutaRespaldo), 'avisos' => $avisos];
        }

        throw new RuntimeException('No se pudo obtener la pieza madre. ' . implode(' | ', $avisos));
    }

    private function mismaUrl(string $a, string $b): bool
    {
        $n = fn (string $u) => rtrim(preg_replace('#^https?://(www\.)?#i', '', strtolower(trim($u))), '/');
        return $n($a) === $n($b);
    }
}
