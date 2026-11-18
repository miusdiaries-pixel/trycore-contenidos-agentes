<?php

namespace App\Services\Transformacion;

use App\Support\Texto;

/** Genera las piezas derivadas de los formatos aptos, las valida y reintenta con otro material si fallan. */
class TransformacionService
{
    private const METODOS = [
        'post_linkedin' => 'post',
        'carrusel_linkedin' => 'carrusel',
        'newsletter' => 'newsletter',
        'guion_video_corto' => 'guion',
        'infografia' => 'infografia',
    ];
    private const MAX_PIEZAS_POR_FORMATO = 2;
    private const INTENTOS = 4;
    private const TEMAS_GENERICOS = ['marca'];

    public function __construct(
        private RedactorReglas $redactor = new RedactorReglas(),
        private ValidadorPieza $validador = new ValidadorPieza(),
    ) {
    }

    /**
     * @param string[] $temasHistorial temas conocidos por el historial
     * @return array{tema: string, piezas: array, omitidos: array, contexto: Contexto}
     */
    public function transformar(array $madre, array $ficha, array $config, array $temasHistorial, string $aaaamm): array
    {
        $url = $ficha['fuente']['url'];
        $x = new Contexto(
            $madre, $config, $ficha, $url,
            $this->slug($url, $madre['titulo']),
            trim(preg_split('/\s[•|–—-]\s/u', $madre['titulo'])[0]),
            $this->detectarTema($madre, $temasHistorial),
            $aaaamm,
        );

        $formatos = [];
        foreach ($config['matriz_formatos'] as $f) {
            $formatos[$f['id']] = $f;
        }

        $piezas = [];
        $omitidos = [];
        foreach ($ficha['aptitud_formatos'] as $a) {
            $f = $formatos[$a['formato']];
            if ($a['apto'] !== true) {
                $omitidos[] = ['formato' => $a['formato'], 'motivo' => $a['razon']];
                continue;
            }
            $metodo = self::METODOS[$a['formato']] ?? null;
            if (!$metodo) {
                $omitidos[] = ['formato' => $a['formato'], 'motivo' => 'No hay redactor automático para este formato'];
                continue;
            }
            $cantidad = min($f['max_por_semana'] ?? 1, self::MAX_PIEZAS_POR_FORMATO);
            $intento = 0;
            for ($n = 1; $n <= $cantidad; $n++) {
                $mejor = null;
                for ($k = 0; $k < self::INTENTOS; $k++) {
                    $r = $this->redactor->$metodo($f, $x, $intento++, $n);
                    if ($r === null) {
                        break;
                    }
                    $pieza = $this->armar($f, $n, $r, $x);
                    $mejor ??= $pieza;
                    if ($pieza['validacion']['ok']) {
                        $mejor = $pieza;
                        break;
                    }
                }
                if ($mejor) {
                    $piezas[] = $mejor;
                } elseif ($n === 1) {
                    $omitidos[] = ['formato' => $a['formato'], 'motivo' => 'No hay contenido suficiente y seguro de la pieza madre para redactarlo'];
                }
            }
        }
        return ['tema' => $x->tema, 'piezas' => $piezas, 'omitidos' => $omitidos, 'contexto' => $x];
    }

    private function armar(array $f, int $n, array $r, Contexto $x): array
    {
        $pieza = [
            'id' => $f['id'] . '-' . $n,
            'formato' => $f['id'],
            'canal' => $f['canal'],
            'numero' => $n,
            'tema' => $x->tema,
            'titulo' => $r['titulo'],
            'contenido' => $r['contenido'],
            'texto' => $r['texto'],
            'enlace' => $r['enlace'],
            'seccion_origen' => $r['seccion'],
        ];
        $pieza['validacion'] = $this->validador->validar($pieza, $f, $x);
        $pieza['estado'] = $pieza['validacion']['ok'] ? 'pendiente_de_aprobacion' : 'requiere_revision';
        return $pieza;
    }

    /** El tema del historial que más se menciona en la pieza madre; si ninguno, «general». */
    private function detectarTema(array $madre, array $temas): string
    {
        $t = Texto::normalizar($madre['texto']);
        $mejor = 'general';
        $max = 0;
        foreach (array_unique($temas) as $tema) {
            if (in_array($tema, self::TEMAS_GENERICOS, true)) {
                continue;
            }
            $n = preg_match_all('/(?<![a-z0-9])' . preg_quote(str_replace('_', ' ', $tema), '/') . '(?![a-z0-9])/u', $t);
            if ($n > $max) {
                $max = $n;
                $mejor = $tema;
            }
        }
        return $mejor;
    }

    private function slug(string $url, string $titulo): string
    {
        $ruta = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $s = Texto::slug(basename($ruta));
        return $s !== '' ? $s : Texto::slug(preg_split('/\s[•|–—-]\s/u', $titulo)[0]);
    }
}
