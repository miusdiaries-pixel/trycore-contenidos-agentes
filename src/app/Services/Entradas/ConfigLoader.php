<?php

namespace App\Services\Entradas;

use RuntimeException;

class ConfigLoader
{
    private const SECCIONES = [
        'productos_vigentes', 'terminos_prohibidos', 'nombres_confidenciales',
        'utm', 'matriz_formatos', 'calendario',
    ];

    public function cargar(string $ruta): array
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No existe el archivo de configuración: $ruta");
        }
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($ruta));
        $config = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);

        foreach (self::SECCIONES as $seccion) {
            if (!array_key_exists($seccion, $config)) {
                throw new RuntimeException("Falta la sección '$seccion' en la configuración");
            }
        }
        return $config;
    }
}