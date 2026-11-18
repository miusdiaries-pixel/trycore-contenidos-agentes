<?php

namespace App\Services\Entradas;

use Carbon\CarbonImmutable;
use RuntimeException;

class CalendarioLoader
{
    /** @return array{inicio: CarbonImmutable, fin: CarbonImmutable} */
    public function ventana(string $ruta, string $zona): array
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No existe el archivo de ventana: $ruta");
        }
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($ruta));
        $d = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);

        $inicio = CarbonImmutable::parse($d['inicio'], $zona)->startOfDay();
        $fin = CarbonImmutable::parse($d['fin'], $zona)->startOfDay();
        if ($fin->lt($inicio)) {
            throw new RuntimeException('La ventana tiene el fin antes del inicio');
        }
        return ['inicio' => $inicio, 'fin' => $fin];
    }

    /** Piezas ya agendadas: cuentan para capacidad y separación por tema. */
    public function ocupado(string $ruta, string $zona): array
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No existe el calendario ocupado: $ruta");
        }
        $h = fopen($ruta, 'r');
        $cabecera = fgetcsv($h, 0, ',', '"', '');
        $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera[0]);

        $piezas = [];
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $f = array_combine($cabecera, array_slice(array_pad($r, count($cabecera), ''), 0, count($cabecera)));
            $piezas[] = [
                'inicio' => CarbonImmutable::createFromFormat('Y-m-d H:i', trim($f['fecha']) . ' ' . trim($f['hora']), $zona),
                'canal' => trim($f['canal']),
                'formato' => trim($f['formato']),
                'tema' => trim($f['tema']),
                'titulo' => trim($f['titulo']),
                'estado' => trim($f['estado']),
                'existente' => true,
            ];
        }
        fclose($h);
        return $piezas;
    }
}