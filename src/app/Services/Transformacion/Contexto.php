<?php

namespace App\Services\Transformacion;

use App\Support\Texto;

/**
 * Todo lo que necesitan los redactores y el validador: la pieza madre, las reglas,
 * el diagnóstico y las frases de la pieza madre que se pueden reutilizar sin romper reglas.
 */
class Contexto
{
    public const NUMERO = '/(?<![\p{L}\p{N}\-])\d+(?:[.,]\d+)*\s?%?/u';

    /** índice de sección => frases reutilizables */
    public array $seguras = [];
    /** cifras que existen en la pieza madre (normalizadas) */
    public array $numerosMadre = [];
    /** título y encabezados de la pieza madre: sus nombres son el tema, no productos ajenos */
    public string $titulosTexto = '';

    public function __construct(
        public array $madre,
        public array $config,
        public array $ficha,
        public string $url,
        public string $slug,
        public string $corto,
        public string $tema,
        public string $aaaamm,
    ) {
        preg_match_all(self::NUMERO, $madre['texto'], $m);
        $this->numerosMadre = array_values(array_unique(array_map(fn ($n) => preg_replace('/\s/', '', $n), $m[0])));

        $this->titulosTexto = $madre['titulo'] . ' ' . implode(' ', array_column($madre['secciones'], 'titulo'));

        foreach ($madre['secciones'] as $i => $s) {
            $frases = [];
            foreach ($s['parrafos'] as $p) {
                foreach (Texto::frases($p) as $f) {
                    if (preg_match('/[.!?]$/u', $f) && $this->esSegura($f)) {
                        $frases[] = $f;
                    }
                }
            }
            $this->seguras[$i] = array_values(array_unique($frases));
        }
    }

    /**
     * Una frase se puede reutilizar si no contiene términos prohibidos, nombres confidenciales,
     * nombres propios que no son productos vigentes ni cifras sin fuente.
     */
    public function esSegura(string $texto, bool $esItem = false): bool
    {
        $len = mb_strlen($texto);
        if ($len < ($esItem ? 15 : 30) || $len > 230 || str_ends_with($texto, ':') || str_contains($texto, 'http')) {
            return false;
        }
        // Una frase que empieza con un conector o un demostrativo depende de la anterior: no sirve suelta.
        if (!$esItem && preg_match('/^(esto|esta|este|estos|estas|ello|ellos|tambien|ademas|asimismo|por eso|por esto|por lo tanto|sin embargo|pero|y|o|de igual forma|por un lado|por otro lado|en consecuencia)(?![a-z])/u', Texto::normalizar($texto))) {
            return false;
        }
        foreach ($this->config['terminos_prohibidos'] as $t) {
            if (Texto::contiene($texto, $t['termino'])) {
                return false;
            }
        }
        foreach ($this->config['nombres_confidenciales'] as $n) {
            if (Texto::contiene($texto, $n)) {
                return false;
            }
        }
        foreach ($this->ficha['nombres_propios_no_vigentes'] as $p) {
            if (Texto::contiene($texto, $p['nombre'])) {
                return false;
            }
        }
        foreach ($this->ficha['cifras'] as $c) {
            if (!$c['con_fuente'] && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($c['valor'], '/') . '(?![\p{N}])/u', $texto)) {
                return false;
            }
        }
        return true;
    }
}
