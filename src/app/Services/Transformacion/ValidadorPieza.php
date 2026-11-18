<?php

namespace App\Services\Transformacion;

use App\Support\Texto;

/** Valida una pieza derivada contra la ficha de su formato y las reglas de Mercadeo. */
class ValidadorPieza
{
    private const PALABRAS_POR_SEGUNDO = 2.5;
    private const GANCHO_VIDEO_MAX_PALABRAS = 10;

    /** @return array{ok: bool, errores: string[], revisar: string[]} */
    public function validar(array $p, array $f, Contexto $x): array
    {
        $err = [];
        $rev = [];
        $c = $p['contenido'];

        match ($p['formato']) {
            'post_linkedin' => $this->post($p, $c, $f, $err),
            'carrusel_linkedin' => $this->carrusel($c, $f, $err),
            'newsletter' => $this->newsletter($p, $c, $f, $err),
            'guion_video_corto' => $this->guion($c, $f, $err),
            'infografia' => $this->infografia($c, $f, $err),
            default => $rev[] = 'Formato sin reglas automáticas de longitud: revisión humana',
        };

        $this->reglasGenerales($p, $x, $err, $rev);
        return ['ok' => !$err, 'errores' => $err, 'revisar' => $rev];
    }

    private function post(array $p, array $c, array $f, array &$err): void
    {
        $n = mb_strlen($p['texto']);
        $min = $f['longitud']['min_caracteres'];
        $max = $f['longitud']['max_caracteres'];
        if ($n < $min || $n > $max) {
            $err[] = "Longitud de $n caracteres; debe estar entre $min y $max";
        }
        if (mb_strlen($c['gancho']) > $f['gancho_max_caracteres']) {
            $err[] = "El gancho supera los {$f['gancho_max_caracteres']} caracteres";
        }
        foreach (['gancho', 'llamado_a_la_accion', 'enlace'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                $err[] = "Falta la parte «{$k}»";
            }
        }
        if (empty($c['desarrollo'])) {
            $err[] = 'Falta el desarrollo';
        }
        foreach ($c['desarrollo'] ?? [] as $i => $par) {
            if (count(Texto::frases($par)) > 2) {
                $err[] = 'El párrafo ' . ($i + 1) . ' tiene más de dos frases';
            }
        }
    }

    private function carrusel(array $c, array $f, array &$err): void
    {
        $laminas = array_merge([$c['portada']], $c['laminas'], [$c['cierre']]);
        $n = count($laminas);
        if ($n < $f['laminas']['min'] || $n > $f['laminas']['max']) {
            $err[] = "El carrusel tiene $n láminas; debe tener entre {$f['laminas']['min']} y {$f['laminas']['max']}";
        }
        foreach ($laminas as $i => $l) {
            $w = $this->palabras($l);
            if ($w > $f['max_palabras_por_lamina']) {
                $err[] = 'La lámina ' . ($i + 1) . " tiene $w palabras; el máximo es {$f['max_palabras_por_lamina']}";
            }
            if (trim($l) === '') {
                $err[] = 'La lámina ' . ($i + 1) . ' está vacía';
            }
        }
    }

    private function newsletter(array $p, array $c, array $f, array &$err): void
    {
        if (mb_strlen($c['asunto']) > $f['asunto_max_caracteres']) {
            $err[] = "El asunto supera los {$f['asunto_max_caracteres']} caracteres";
        }
        $cuerpo = $c['introduccion'] . ' ' . implode(' ', array_map(fn ($s) => $s['subtitulo'] . ' ' . $s['texto'], $c['secciones'])) . ' ' . $c['llamado_a_la_accion'];
        $w = $this->palabras($cuerpo);
        if ($w < $f['longitud']['min_palabras'] || $w > $f['longitud']['max_palabras']) {
            $err[] = "El newsletter tiene $w palabras; debe tener entre {$f['longitud']['min_palabras']} y {$f['longitud']['max_palabras']}";
        }
        if (count($c['secciones']) !== 3) {
            $err[] = 'El newsletter debe tener 3 secciones';
        }
        foreach ($c['secciones'] as $i => $s) {
            if (trim($s['subtitulo']) === '' || trim($s['texto']) === '') {
                $err[] = 'La sección ' . ($i + 1) . ' no tiene subtítulo o texto';
            }
        }
        if (preg_match_all('#https?://#', $p['texto']) !== 1) {
            $err[] = 'Debe haber un solo llamado a la acción con un solo enlace';
        }
    }

    private function guion(array $c, array $f, array &$err): void
    {
        $w = $this->palabras($c['gancho'] . ' ' . implode(' ', $c['puntos']) . ' ' . $c['cierre']);
        if ($w < $f['palabras']['min'] || $w > $f['palabras']['max']) {
            $err[] = "El guion tiene $w palabras; debe tener entre {$f['palabras']['min']} y {$f['palabras']['max']}";
        }
        $seg = (int) round($w / self::PALABRAS_POR_SEGUNDO);
        if ($seg < $f['duracion_segundos']['min'] || $seg > $f['duracion_segundos']['max']) {
            $err[] = "Duración estimada de $seg s; debe estar entre {$f['duracion_segundos']['min']} y {$f['duracion_segundos']['max']}";
        }
        if (count($c['puntos']) !== 3) {
            $err[] = 'El guion debe tener 3 puntos';
        }
        if ($this->palabras($c['gancho']) > self::GANCHO_VIDEO_MAX_PALABRAS) {
            $err[] = 'El gancho es muy largo para los primeros 3 segundos';
        }
        foreach ($c['puntos'] as $i => $pt) {
            foreach (Texto::frases($pt) as $fr) {
                if ($this->palabras($fr) > 20) {
                    $err[] = 'El punto ' . ($i + 1) . ' tiene frases largas para decir de corrido';
                }
            }
        }
    }

    private function infografia(array $c, array $f, array &$err): void
    {
        $n = count($c['elementos']);
        if ($n < $f['elementos']['min'] || $n > $f['elementos']['max']) {
            $err[] = "La infografía tiene $n elementos; debe tener entre {$f['elementos']['min']} y {$f['elementos']['max']}";
        }
        if (trim($c['titulo']) === '') {
            $err[] = 'Falta el título';
        }
        foreach ($c['elementos'] as $i => $e) {
            if ($this->palabras($e['texto']) > $f['max_palabras_por_elemento']) {
                $err[] = 'El elemento ' . ($i + 1) . " supera las {$f['max_palabras_por_elemento']} palabras";
            }
            if (trim($e['fuente'] ?? '') === '') {
                $err[] = 'El elemento ' . ($i + 1) . ' no tiene fuente';
            }
        }
    }

    private function reglasGenerales(array $p, Contexto $x, array &$err, array &$rev): void
    {
        $t = $p['texto'];

        foreach ($x->config['terminos_prohibidos'] as $term) {
            if (Texto::contiene($t, $term['termino'])) {
                $err[] = "Contiene el término prohibido «{$term['termino']}»";
            }
        }
        foreach ($x->config['nombres_confidenciales'] as $n) {
            if (Texto::contiene($t, $n)) {
                $err[] = "Contiene el nombre confidencial «{$n}»";
            }
        }
        foreach ($x->ficha['nombres_propios_no_vigentes'] as $n) {
            if (Texto::contiene($t, $n['nombre']) && !Texto::contiene($x->titulosTexto, $n['nombre'])) {
                $rev[] = "Menciona «{$n['nombre']}», que no es un producto vigente: revisar";
            }
        }

        // Cifras: deben existir en la pieza madre; si allí no tienen fuente, se marcan para revisión.
        $sinUrl = preg_replace('#https?://\S+#u', '', $t);
        preg_match_all(Contexto::NUMERO, $sinUrl, $m);
        $sinFuente = array_map(fn ($c) => preg_replace('/\s/', '', $c['valor']), array_filter($x->ficha['cifras'], fn ($c) => !$c['con_fuente']));
        foreach (array_unique(array_map(fn ($n) => preg_replace('/\s/', '', $n), $m[0])) as $n) {
            if (!in_array($n, $x->numerosMadre, true)) {
                $err[] = "La cifra «{$n}» no existe en la pieza madre (posible invención)";
            } elseif (in_array($n, $sinFuente, true)) {
                $rev[] = "La cifra «{$n}» no tiene fuente en la pieza madre: revisar";
            }
        }

        // UTM: todo enlace debe llevar los parámetros de la convención.
        preg_match_all('#https?://[^\s<>"\')]+#u', $t, $urls);
        $esperados = Utm::params($x->config, $p['canal'], $p['formato'], $p['numero'], $x->slug, $x->aaaamm);
        foreach ($urls[0] as $url) {
            parse_str((string) parse_url(rtrim($url, '.,;:'), PHP_URL_QUERY), $q);
            foreach ($esperados as $k => $v) {
                if (($q[$k] ?? null) !== $v) {
                    $err[] = "El enlace no tiene $k correcto (esperado «{$v}»)";
                }
                if (isset($q[$k]) && !preg_match('/^[a-z0-9-]+$/', $q[$k])) {
                    $err[] = "El parámetro $k debe ir en minúsculas, sin tildes ni espacios";
                }
            }
        }
        if (in_array($p['formato'], ['post_linkedin', 'newsletter'], true) && !$urls[0]) {
            $err[] = 'Falta el enlace con UTM';
        }
    }

    private function palabras(string $t): int
    {
        return Texto::palabras(preg_replace('#https?://\S+#u', '', $t));
    }
}
