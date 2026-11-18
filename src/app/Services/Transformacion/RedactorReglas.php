<?php

namespace App\Services\Transformacion;

use App\Support\Texto;

/**
 * Redactor sin IA: arma cada pieza con frases de la pieza madre y plantillas neutras,
 * siguiendo la estructura y las técnicas de la ficha de cada formato. Cada método recibe
 * un "intento" para elegir el siguiente candidato cuando el anterior no pasó la validación.
 * Devuelve null si no hay material suficiente.
 */
class RedactorReglas
{
    private const CONECTORES = ['Sigue →', 'Hay más →', 'Mira lo que sigue →', 'Y esto continúa →', 'Pasa a la siguiente →'];
    private const RELLENO = ['de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'del', 'con', 'para', 'por', 'un', 'una', 'que'];

    public function post(array $f, Contexto $x, int $intento, int $numero): ?array
    {
        $cands = [];
        foreach ($x->seguras as $i => $frases) {
            if (count($frases) >= 5 && !$x->madre['secciones'][$i]['implicita']) {
                $cands[] = [$i, $frases];
            }
        }
        usort($cands, fn ($a, $b) => count($b[1]) <=> count($a[1]) ?: $a[0] <=> $b[0]);
        if (!isset($cands[$intento])) {
            return null;
        }
        [$i, $frases] = $cands[$intento];
        $titulo = $x->madre['secciones'][$i]['titulo'];
        $gancho = $this->gancho($titulo, $f['gancho_max_caracteres']);
        $enlace = Utm::construir($x->config, $f['canal'], $f['id'], $numero, $x->slug, $x->aaaamm, $x->url);
        $cta = '¿Qué opinas? Cuéntanos tu experiencia en los comentarios. Si quieres profundizar, lee el contenido completo:';

        $min = $f['longitud']['min_caracteres'];
        $max = $f['longitud']['max_caracteres'];
        $parrafos = [];
        $actual = [];
        // Cierre normal (no «fn»): debe leer $parrafos y $actual tal como van cambiando.
        $armar = function () use (&$parrafos, &$actual, $gancho, $cta, $enlace) {
            return $gancho . "\n\n" . implode("\n\n", array_merge($parrafos, $actual ? [implode(' ', $actual)] : [])) . "\n\n" . $cta . "\n" . $enlace;
        };

        foreach ($frases as $fr) {
            $actual[] = $fr;
            if (mb_strlen($armar()) > $max) {
                array_pop($actual);
                break;
            }
            if (count($actual) === 2) {
                $parrafos[] = implode(' ', $actual);
                $actual = [];
            }
            if (mb_strlen($armar()) >= $min + 100) {
                break;
            }
        }
        if ($actual) {
            $parrafos[] = implode(' ', $actual);
        }

        return [
            'contenido' => ['gancho' => $gancho, 'desarrollo' => $parrafos, 'llamado_a_la_accion' => $cta, 'enlace' => $enlace],
            'texto' => $gancho . "\n\n" . implode("\n\n", $parrafos) . "\n\n" . $cta . "\n" . $enlace,
            'titulo' => $gancho,
            'enlace' => $enlace,
            'seccion' => $titulo,
        ];
    }

    public function carrusel(array $f, Contexto $x, int $intento, int $numero): ?array
    {
        $min = $f['laminas']['min'];
        $max = $f['laminas']['max'];
        $cands = $this->candidatos($x, $f['max_palabras_por_lamina'] - 6, $min - 2);
        if (!isset($cands[$intento])) {
            return null;
        }
        $c = $cands[$intento];
        $tema = $this->tema($c['titulo']);
        $enlace = Utm::construir($x->config, $f['canal'], $f['id'], $numero, $x->slug, $x->aaaamm, $x->url);

        $portada = 'Lo esencial sobre ' . $this->cortar($tema, 12) . ', en pocas láminas';
        $laminas = [];
        foreach (array_slice($c['items'], 0, $max - 2) as $k => $item) {
            $item = rtrim($item, ' ,;:');
            $item .= preg_match('/[.!?]$/u', $item) ? '' : '.';
            $laminas[] = $item . ' ' . self::CONECTORES[$k % count(self::CONECTORES)];
        }
        $cierre = 'Ahora conoces lo esencial sobre ' . $this->cortar($tema, 10) . '. Tu siguiente paso: lee el contenido completo. ' . $enlace;

        return [
            'contenido' => ['portada' => $portada, 'laminas' => $laminas, 'cierre' => $cierre],
            'texto' => $portada . "\n" . implode("\n", $laminas) . "\n" . $cierre,
            'titulo' => $portada,
            'enlace' => $enlace,
            'seccion' => $c['titulo'],
        ];
    }

    public function newsletter(array $f, Contexto $x, int $intento, int $numero): ?array
    {
        $cands = [];
        foreach ($x->seguras as $i => $frases) {
            if (count($frases) >= 3 && !$x->madre['secciones'][$i]['implicita']) {
                $cands[] = $i;
            }
        }
        if (count($cands) < 3) {
            return null;
        }
        if ($intento > count($cands) - 3) {
            return null;
        }
        $ids = array_slice($cands, $intento, 3);
        $enlace = Utm::construir($x->config, $f['canal'], $f['id'], $numero, $x->slug, $x->aaaamm, $x->url);

        $temas = array_map(fn ($i) => $this->cortar($this->tema($x->madre['secciones'][$i]['titulo']), 8), $ids);
        $intro = ['En este correo repasamos tres ideas clave de ' . $x->corto . ': ' . $temas[0] . ', ' . $temas[1] . ' y ' . $temas[2] . '.'];
        $desc = $x->madre['descripcion'];
        if ($desc !== '' && $x->esSegura($desc)) {
            $intro[] = $desc;
        }
        $introduccion = implode(' ', $intro);
        $cta = 'Si quieres profundizar, lee el contenido completo: ' . $enlace;

        $asunto = null;
        foreach (['Tres ideas clave: ' . $x->corto, $x->corto] as $cand) {
            if (mb_strlen($cand) <= $f['asunto_max_caracteres']) {
                $asunto = $cand;
                break;
            }
        }
        $asunto ??= rtrim(mb_substr($x->corto, 0, $f['asunto_max_caracteres'] - 1)) . '…';

        $min = $f['longitud']['min_palabras'];
        $max = $f['longitud']['max_palabras'];
        for ($por = 3; $por <= 6; $por++) {
            $secciones = [];
            foreach ($ids as $i) {
                $secciones[] = [
                    'subtitulo' => $this->subtitulo($x->madre['secciones'][$i]['titulo']),
                    'texto' => implode(' ', array_slice($x->seguras[$i], 0, $por)),
                ];
            }
            $palabras = $this->palabras($introduccion . ' ' . implode(' ', array_map(fn ($s) => $s['subtitulo'] . ' ' . $s['texto'], $secciones)) . ' ' . $cta);
            if ($palabras >= $min + 20) {
                break;
            }
        }

        $texto = $asunto . "\n\n" . $introduccion . "\n\n"
            . implode("\n\n", array_map(fn ($s) => $s['subtitulo'] . "\n" . $s['texto'], $secciones)) . "\n\n" . $cta;

        return [
            'contenido' => ['asunto' => $asunto, 'introduccion' => $introduccion, 'secciones' => $secciones, 'llamado_a_la_accion' => $cta],
            'texto' => $texto,
            'titulo' => $asunto,
            'enlace' => $enlace,
            'seccion' => implode(' / ', array_map(fn ($i) => $x->madre['secciones'][$i]['titulo'], $ids)),
        ];
    }

    public function guion(array $f, Contexto $x, int $intento, int $numero): ?array
    {
        $min = $f['palabras']['min'];
        $max = $f['palabras']['max'];
        $cands = [];
        foreach ($x->seguras as $i => $frases) {
            $cortas = array_values(array_filter($frases, fn ($s) => $this->palabras($s) <= 20));
            if (count($cortas) >= 2 && !$x->madre['secciones'][$i]['implicita']) {
                $cands[$i] = $cortas;
            }
        }
        $claves = array_keys($cands);
        if (count($claves) < 3 || $intento > count($claves) - 3) {
            return null;
        }
        $ids = array_slice($claves, $intento, 3);

        $tema = $this->tema($x->madre['secciones'][$ids[0]]['titulo']);
        $gancho = str_contains($x->madre['secciones'][$ids[0]]['titulo'], '?') && $this->palabras($tema) <= 7
            ? '¿' . $this->mayuscula($tema) . '?'
            : '¿Qué tanto sabes sobre ' . $this->cortar($tema, 4) . '?';
        $cierre = 'Si quieres saber más, lee el contenido completo. Y cuéntanos qué opinas en los comentarios.';

        $puntos = [];
        foreach ($ids as $i) {
            $puntos[] = [array_shift($cands[$i])];
        }
        $total = fn () => $this->palabras($gancho) + $this->palabras($cierre) + array_sum(array_map(fn ($p) => $this->palabras(implode(' ', $p)), $puntos));
        for ($vuelta = 0; $total() < $min + 6 && $vuelta < 9; $vuelta++) {
            $k = $vuelta % 3;
            $sig = $cands[$ids[$k]][0] ?? null;
            if ($sig === null || $total() + $this->palabras($sig) > $max - 4) {
                continue;
            }
            $puntos[$k][] = array_shift($cands[$ids[$k]]);
        }
        $textos = array_map(fn ($p) => implode(' ', $p), $puntos);
        $palabras = $total();

        return [
            'contenido' => [
                'gancho' => $gancho,
                'puntos' => $textos,
                'cierre' => $cierre,
                'palabras' => $palabras,
                'duracion_estimada_segundos' => (int) round($palabras / 2.5),
            ],
            'texto' => $gancho . "\n" . implode("\n", $textos) . "\n" . $cierre,
            'titulo' => $gancho,
            'enlace' => null,
            'seccion' => implode(' / ', array_map(fn ($i) => $x->madre['secciones'][$i]['titulo'], $ids)),
        ];
    }

    public function infografia(array $f, Contexto $x, int $intento, int $numero): ?array
    {
        $cands = $this->candidatos($x, $f['max_palabras_por_elemento'], $f['elementos']['min']);
        if (!isset($cands[$intento])) {
            return null;
        }
        $c = $cands[$intento];
        $titulo = 'Lo que debes saber sobre ' . $this->cortar($this->tema($c['titulo']), 12);
        $fuente = 'Fuente: ' . $x->corto . ', sección «' . $this->subtitulo($c['titulo']) . '»';
        $elementos = array_map(
            fn ($t) => ['texto' => rtrim($t, ' ,;:'), 'fuente' => $fuente],
            array_slice($c['items'], 0, $f['elementos']['max'])
        );

        return [
            'contenido' => ['titulo' => $titulo, 'elementos' => $elementos],
            'texto' => $titulo . "\n" . implode("\n", array_map(fn ($e) => $e['texto'] . ' (' . $e['fuente'] . ')', $elementos)),
            'titulo' => $titulo,
            'enlace' => null,
            'seccion' => $c['titulo'],
        ];
    }

    /**
     * Listas (o, si no hay, frases de una sección) con suficientes elementos cortos y reutilizables.
     * Primero las listas de 5 a 8 elementos.
     */
    private function candidatos(Contexto $x, int $maxPalabras, int $min): array
    {
        $res = [];
        foreach ($x->madre['listas'] as $l) {
            $items = array_values(array_filter(
                array_map(fn ($i) => rtrim($i, ' :'), $l['items']),
                fn ($i) => $x->esSegura($i, true) && $this->palabras($i) <= $maxPalabras
            ));
            if (count($items) >= $min) {
                $res[] = ['titulo' => $l['seccion'], 'items' => $items, 'origen' => 0];
            }
        }
        foreach ($x->seguras as $i => $frases) {
            $cortas = array_values(array_filter($frases, fn ($s) => $this->palabras($s) <= $maxPalabras));
            if (count($cortas) >= $min && !$x->madre['secciones'][$i]['implicita']) {
                $res[] = ['titulo' => $x->madre['secciones'][$i]['titulo'], 'items' => $cortas, 'origen' => 1];
            }
        }
        usort($res, function ($a, $b) {
            $ra = count($a['items']) >= 5 && count($a['items']) <= 8 ? 0 : 1;
            $rb = count($b['items']) >= 5 && count($b['items']) <= 8 ? 0 : 1;
            return [$a['origen'], $ra, -count($a['items'])] <=> [$b['origen'], $rb, -count($b['items'])];
        });
        return $res;
    }

    private function gancho(string $titulo, int $max): string
    {
        $tema = $this->tema($titulo);
        $g = str_contains($titulo, '?') ? '¿' . $this->mayuscula($tema) . '?' : '¿Qué tanto sabes sobre ' . $tema . '?';
        return mb_strlen($g) <= $max ? $g : '¿Qué tanto sabes sobre ' . $this->cortar($tema, 8) . '?';
    }

    /** «¿Cómo funciona X?» => «cómo funciona X»; quita numeración y pone minúscula inicial (salvo siglas). */
    private function tema(string $titulo): string
    {
        $t = Texto::limpiar($titulo);
        $t = preg_replace('/^\d+[\.\)]\s*/u', '', $t);
        if (str_contains($t, '?')) {
            $t = explode('?', $t)[0];
        }
        $t = rtrim(ltrim($t, '¿¡ '), ' .:;!?');
        if (!preg_match('/^\p{Lu}{2,}/u', $t)) {
            $t = mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
        }
        return $t;
    }

    private function subtitulo(string $titulo): string
    {
        $t = Texto::limpiar($titulo);
        if (($p = mb_strpos($t, '?')) !== false) {
            $t = mb_substr($t, 0, $p + 1);
        }
        return $t;
    }

    private function cortar(string $texto, int $maxPalabras): string
    {
        $p = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
        if (count($p) <= $maxPalabras) {
            return $texto;
        }
        $p = array_slice($p, 0, $maxPalabras);
        while ($p && in_array(mb_strtolower(end($p)), self::RELLENO, true)) {
            array_pop($p);
        }
        return implode(' ', $p);
    }

    private function mayuscula(string $t): string
    {
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    private function palabras(string $t): int
    {
        return Texto::palabras(preg_replace('#https?://\S+#u', '', $t));
    }
}
