<?php

namespace App\Services\Diagnostico;

use App\Support\Texto;

/**
 * Ficha de diagnóstico de una pieza madre: indicadores medibles, alertas según las
 * reglas del config y aptitud para cada formato de la matriz.
 */
class DiagnosticoService
{
    /** Una sección cuenta como "tema desarrollable" si tiene al menos estas palabras. */
    private const PALABRAS_TEMA = 60;
    private const TEXTO_MINIMO = 300;
    private const FRASE_LARGA = 28;

    private const FUENTE = '/(?<![a-z])(segun|fuente|de acuerdo con|estudio|informe|encuesta|investigacion|reporte|estadistica)(?![a-z])|https?:\/\//u';
    private const CARGO = '/(gerente|director|directora|jefe|jefa|ceo|cto|cfo|lider|coordinador|coordinadora|vicepresidente|presidente)/u';

    public function diagnosticar(array $c, array $config, array $fuente): array
    {
        $cuerpo = $this->cuerpo($c);
        $frases = Texto::frases($cuerpo);
        $cifras = $this->cifras($frases);
        $marca = $this->marca($fuente['url'] ?? '');
        $vigentes = $this->productosVigentes($cuerpo, $config['productos_vigentes']);
        $propios = $this->nombresPropios($frases, $config['productos_vigentes'], $marca);

        $temas = [];
        foreach ($c['secciones'] as $s) {
            $temas[] = ['titulo' => $s['titulo'], 'palabras' => $s['palabras'], 'desarrollable' => $s['palabras'] >= self::PALABRAS_TEMA];
        }
        $desarrollables = count(array_filter($temas, fn ($t) => $t['desarrollable']));
        $mayorLista = max(array_merge([0], array_map(fn ($l) => count($l['items']), $c['listas'])));
        $pasos = $this->pasosNumerados($c);
        $porFrase = count($frases) ? round(Texto::palabras($cuerpo) / count($frases), 1) : 0;

        $indicadores = [
            'palabras' => $c['palabras'],
            'secciones' => count(array_filter($c['secciones'], fn ($s) => !$s['implicita'])),
            'temas_desarrollables' => $desarrollables,
            'listas' => count($c['listas']),
            'elementos_mayor_lista' => $mayorLista,
            'pasos_numerados' => $pasos,
            'cifras' => count($cifras),
            'cifras_sin_fuente' => count(array_filter($cifras, fn ($x) => !$x['con_fuente'])),
            'palabras_por_frase' => $porFrase,
            'legibilidad' => $porFrase <= 20 ? 'buena' : ($porFrase <= self::FRASE_LARGA ? 'media' : 'baja'),
            'productos_vigentes_mencionados' => $vigentes,
        ];

        $aptitud = [];
        foreach ($config['matriz_formatos'] as $f) {
            $aptitud[] = $this->aptitud($f, $c, $indicadores, $cifras);
        }

        return [
            'fuente' => $fuente,
            'titulo' => $c['titulo'],
            'descripcion' => $c['descripcion'],
            'indicadores' => $indicadores,
            'temas' => $temas,
            'cifras' => $cifras,
            'nombres_propios_no_vigentes' => $propios,
            'aptitud_formatos' => $aptitud,
            'alertas' => $this->alertas($c, $config, $fuente, $indicadores, $cifras, $propios),
        ];
    }

    /** Texto corrido (párrafos y elementos de lista), sin títulos. */
    private function cuerpo(array $c): string
    {
        $partes = [];
        foreach ($c['secciones'] as $s) {
            foreach (array_merge($s['parrafos'], $s['items']) as $p) {
                // Cada párrafo o elemento de lista termina en punto, para separar bien las frases.
                $partes[] = preg_match('/[.!?:;…]$/u', $p) ? $p : $p . '.';
            }
        }
        return implode(' ', $partes);
    }

    /** Todas las cifras relevantes con la frase donde aparecen y si esa frase cita fuente. */
    public function cifras(array $frases): array
    {
        $res = [];
        foreach ($frases as $f) {
            if (!preg_match_all('/(?<![\p{L}\-])\d+(?:[.,]\d+)*\s?%?/u', $f, $m)) {
                continue;
            }
            foreach ($m[0] as $tok) {
                $valor = trim($tok);
                $digitos = preg_replace('/\D/', '', $valor);
                $esAnio = preg_match('/^(19|20)\d{2}$/', $digitos) && !str_contains($valor, '%');
                if ($esAnio || (strlen($digitos) < 2 && !str_contains($valor, '%'))) {
                    continue;
                }
                $res[] = [
                    'valor' => $valor,
                    'frase' => mb_substr($f, 0, 160),
                    'con_fuente' => (bool) preg_match(self::FUENTE, Texto::normalizar($f)),
                ];
            }
        }
        return $res;
    }

    private function pasosNumerados(array $c): int
    {
        $n = 0;
        foreach ($c['secciones'] as $s) {
            foreach ($s['subtitulos'] as $t) {
                if (preg_match('/^\d+[\.\)]\s/u', $t)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    private function productosVigentes(string $cuerpo, array $vigentes): array
    {
        $enc = [];
        foreach ($vigentes as $p) {
            $veces = preg_match_all('/(?<![a-z0-9])' . preg_quote(Texto::normalizar($p), '/') . '(?![a-z0-9])/u', Texto::normalizar($cuerpo));
            if ($veces) {
                $enc[$p] = $veces;
            }
        }
        return $enc;
    }

    private function marca(string $url): string
    {
        $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
        return Texto::normalizar(explode('.', $host)[0] ?? '');
    }

    /**
     * Nombres propios a mitad de frase que no son productos vigentes. Sirven para vigilar
     * que las piezas derivadas no mencionen productos fuera de la lista.
     */
    private function nombresPropios(array $frases, array $vigentes, string $marca): array
    {
        $stop = array_map([Texto::class, 'normalizar'], [
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre',
            'Noviembre', 'Diciembre', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo',
            'Colombia', 'Latinoamérica', 'América',
        ]);
        $vig = array_map([Texto::class, 'normalizar'], $vigentes);
        $cuenta = [];

        foreach ($frases as $f) {
            $actual = [];
            $volcar = function () use (&$actual, &$cuenta, $marca, $vig) {
                if ($actual) {
                    $nombre = implode(' ', $actual);
                    $n = Texto::normalizar($nombre);
                    $esVigente = array_filter($vig, fn ($v) => str_contains($n, $v));
                    if ($n !== $marca && !str_contains($n, $marca ?: "\0") && !$esVigente) {
                        $cuenta[$nombre] = ($cuenta[$nombre] ?? 0) + 1;
                    }
                }
                $actual = [];
            };
            foreach (preg_split('/\s+/u', trim($f), -1, PREG_SPLIT_NO_EMPTY) as $i => $tok) {
                $limpio = trim($tok, "¿¡«»\"'“”‘’()[]{},.;:!?");
                $esNombre = $i > 0
                    && mb_strlen($limpio) >= 3
                    && preg_match('/^\p{Lu}\p{Ll}[\p{L}\p{N}]*$/u', $limpio)
                    && !in_array(Texto::normalizar($limpio), $stop, true);
                if ($esNombre) {
                    $actual[] = $limpio;
                    if (preg_match('/[,.;:!?)]$/u', $tok)) {
                        $volcar();
                    }
                } else {
                    $volcar();
                }
            }
            $volcar();
        }
        arsort($cuenta);
        $out = [];
        foreach (array_slice($cuenta, 0, 20, true) as $nombre => $veces) {
            $out[] = ['nombre' => $nombre, 'veces' => $veces];
        }
        return $out;
    }

    private function aptitud(array $f, array $c, array $ind, array $cifras): array
    {
        $id = $f['id'];
        $req = $f['requiere_de_pieza_madre'] ?? [];
        $min = $this->minimo($req, 3);
        $listaOPasos = max($ind['elementos_mayor_lista'], $ind['pasos_numerados']);

        [$apto, $razon] = match ($id) {
            'post_linkedin' => $ind['temas_desarrollables'] >= 1
                ? [true, 'Hay al menos una sección con desarrollo suficiente para una idea central']
                : [false, 'Ninguna sección tiene desarrollo suficiente para una idea central'],
            'carrusel_linkedin' => $listaOPasos >= $min
                ? [true, "Hay una lista o pasos de $listaOPasos elementos (mínimo $min)"]
                : [false, "La mayor lista o secuencia tiene $listaOPasos elementos y se piden $min"],
            'newsletter' => $ind['temas_desarrollables'] >= $min
                ? [true, "Hay {$ind['temas_desarrollables']} temas desarrollables (mínimo $min)"]
                : [false, "Hay {$ind['temas_desarrollables']} temas desarrollables y se piden $min"],
            'guion_video_corto' => max($ind['temas_desarrollables'], $ind['elementos_mayor_lista']) >= $min
                ? [true, "Hay al menos $min puntos claros"]
                : [false, "No hay $min puntos claros"],
            'infografia' => $this->infografia($listaOPasos, $c, $cifras),
            'caso_de_exito' => $this->casoExito($c, $cifras),
            default => [null, 'Sin regla automática para este formato: requiere revisión humana'],
        };

        return ['formato' => $id, 'apto' => $apto, 'razon' => $razon, 'requiere' => $req];
    }

    private function infografia(int $elementos, array $c, array $cifras): array
    {
        if ($elementos >= 5) {
            return [true, "Hay una lista o pasos de $elementos elementos; se usarán 5 a 7"];
        }
        return [false, "No hay 5 a 7 datos o pasos (el mayor tiene $elementos)"];
    }

    private function casoExito(array $c, array $cifras): array
    {
        $texto = implode(' ', array_merge(...array_map(fn ($s) => $s['parrafos'], $c['secciones'])));
        $cita = preg_match('/[“"«][^”"»]{40,}[”"»]/u', $texto, $m, PREG_OFFSET_CAPTURE)
            && preg_match(self::CARGO, Texto::normalizar(mb_substr($texto, mb_strlen(substr($texto, 0, $m[0][1])), 400)));
        $medible = count(array_filter($cifras, fn ($x) => str_contains($x['valor'], '%') && $x['con_fuente'])) > 0;

        if ($cita && $medible) {
            return [true, 'Hay un testimonio con cargo y resultados medibles con fuente'];
        }
        $falta = [];
        if (!$cita) {
            $falta[] = 'testimonio de un cliente real con nombre y cargo';
        }
        if (!$medible) {
            $falta[] = 'resultados medibles con fuente';
        }
        return [false, 'La pieza madre no tiene: ' . implode(' ni ', $falta)];
    }

    /** Lee "al menos N" o "N a M" de los requisitos escritos en el config. */
    private function minimo(array $requisitos, int $defecto): int
    {
        foreach ($requisitos as $r) {
            if (preg_match('/al menos (\d+)/u', $r, $m) || preg_match('/(\d+) a \d+/u', $r, $m)) {
                return (int) $m[1];
            }
        }
        return $defecto;
    }

    private function alertas(array $c, array $config, array $fuente, array $ind, array $cifras, array $propios): array
    {
        $a = [];
        $add = function (string $nivel, string $tipo, string $mensaje, ?string $detalle = null) use (&$a) {
            $a[] = ['nivel' => $nivel, 'tipo' => $tipo, 'mensaje' => $mensaje, 'detalle' => $detalle];
        };

        if (($fuente['origen'] ?? '') !== 'web') {
            $add('alta', 'fuente', 'No se pudo descargar la URL: se usó la copia local de respaldo', implode(' | ', $fuente['avisos'] ?? []));
        }
        if ($ind['palabras'] < self::TEXTO_MINIMO) {
            $add('alta', 'contenido', "Texto extraído muy corto ({$ind['palabras']} palabras): la página pudo cambiar de estructura");
        }
        foreach ($config['terminos_prohibidos'] as $t) {
            if (Texto::contiene($c['texto'], $t['termino'])) {
                $add('media', 'termino_prohibido', "La pieza madre contiene «{$t['termino']}»: no debe pasar a las derivadas. {$t['motivo']}", Texto::contexto($c['texto'], $t['termino']));
            }
        }
        foreach ($config['nombres_confidenciales'] as $nombre) {
            if (Texto::contiene($c['texto'], $nombre)) {
                $add('alta', 'nombre_confidencial', "La pieza madre menciona a «{$nombre}», nombre confidencial", Texto::contexto($c['texto'], $nombre));
            }
        }
        foreach ($cifras as $x) {
            if (!$x['con_fuente']) {
                $add('media', 'cifra_sin_fuente', "Cifra «{$x['valor']}» sin fuente en la pieza madre: marcar para revisión", $x['frase']);
            }
        }
        if (!$ind['productos_vigentes_mencionados']) {
            $add('info', 'productos', 'La pieza madre no menciona ningún producto vigente');
        }
        if ($propios) {
            $lista = implode(', ', array_map(fn ($p) => $p['nombre'], array_slice($propios, 0, 8)));
            $add('info', 'nombres_propios', 'Nombres propios que no son productos vigentes (no deben aparecer como productos en las derivadas): ' . $lista);
        }
        if ($ind['legibilidad'] === 'baja') {
            $add('media', 'legibilidad', "Frases muy largas ({$ind['palabras_por_frase']} palabras en promedio)");
        }
        return $a;
    }
}
