<?php

namespace Tests\Support;

use App\Services\Transformacion\Contexto;

/** Reglas y piezas mínimas para probar sin depender de los archivos del reto. */
class Fixtures
{
    public static function config(): array
    {
        return [
            'productos_vigentes' => ['Docfly', 'ORIUNO'],
            'terminos_prohibidos' => [
                ['termino' => 'GPT', 'motivo' => 'No se nombran modelos de IA'],
                ['termino' => 'sin errores', 'motivo' => 'Promesa absoluta'],
            ],
            'nombres_confidenciales' => ['Banco Andino del Sur'],
            'utm' => [
                'utm_source' => ['linkedin' => 'linkedin', 'newsletter' => 'newsletter'],
                'utm_medium' => ['linkedin' => 'social-organico', 'newsletter' => 'email'],
                'utm_campaign' => '{slug_pieza_madre}-{aaaamm}',
                'utm_content' => '{formato}-{numero}',
            ],
            'matriz_formatos' => [
                [
                    'id' => 'post_linkedin', 'canal' => 'linkedin', 'max_por_semana' => 3,
                    'longitud' => ['min_caracteres' => 600, 'max_caracteres' => 1300], 'gancho_max_caracteres' => 140,
                ],
                [
                    'id' => 'carrusel_linkedin', 'canal' => 'linkedin', 'max_por_semana' => 2,
                    'laminas' => ['min' => 6, 'max' => 10], 'max_palabras_por_lamina' => 30,
                ],
            ],
            'calendario' => [
                'zona_horaria' => 'America/Bogota',
                'dias_habiles' => ['lunes', 'martes', 'miércoles', 'jueves', 'viernes'],
                'franjas_de_publicacion' => ['08:30', '11:00', '15:00'],
                'capacidad_max_por_dia' => 2,
                'capacidad_max_por_semana' => 4,
                'separacion_minima_mismo_tema_horas' => 48,
                'festivos' => [['fecha' => '2026-12-08', 'nombre' => 'La Inmaculada Concepción']],
            ],
        ];
    }

    public static function contexto(string $textoMadre = 'La automatización mejora procesos. Un 40% de ahorro.'): Contexto
    {
        $ficha = ['nombres_propios_no_vigentes' => [], 'cifras' => [['valor' => '40%', 'frase' => '', 'con_fuente' => false]]];
        $madre = ['titulo' => 'Guía de prueba', 'descripcion' => '', 'secciones' => [], 'listas' => [], 'texto' => $textoMadre, 'palabras' => 10];
        return new Contexto($madre, self::config(), $ficha, 'https://ejemplo.com/guia-de-prueba/', 'guia-de-prueba', 'Guía de prueba', 'rpa', '202611');
    }

    public static function enlace(string $formato = 'post_linkedin', int $n = 1): string
    {
        return 'https://ejemplo.com/guia-de-prueba/?utm_source=linkedin&utm_medium=social-organico'
            . "&utm_campaign=guia-de-prueba-202611&utm_content=" . str_replace('_', '-', $formato) . "-$n";
    }

    /** Un post válido: 700 caracteres aprox., gancho corto, enlace con UTM. */
    public static function post(array $cambios = []): array
    {
        $parrafo = 'La automatización mejora procesos repetitivos en las empresas. Los equipos ganan tiempo para tareas de más valor.';
        $contenido = array_merge([
            'gancho' => '¿Qué tanto sabes sobre automatización?',
            'desarrollo' => array_fill(0, 6, $parrafo),
            'llamado_a_la_accion' => '¿Qué opinas? Cuéntanos en los comentarios.',
            'enlace' => self::enlace(),
        ], $cambios);
        $texto = $contenido['gancho'] . "\n\n" . implode("\n\n", $contenido['desarrollo']) . "\n\n" . $contenido['llamado_a_la_accion'] . "\n" . $contenido['enlace'];
        return ['id' => 'post_linkedin-1', 'formato' => 'post_linkedin', 'canal' => 'linkedin', 'numero' => 1, 'tema' => 'rpa',
            'titulo' => $contenido['gancho'], 'contenido' => $contenido, 'texto' => $texto, 'enlace' => $contenido['enlace']];
    }
}
