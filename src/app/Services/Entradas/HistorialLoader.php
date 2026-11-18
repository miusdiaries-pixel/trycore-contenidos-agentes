<?php

namespace App\Services\Entradas;

use Carbon\CarbonImmutable;
use RuntimeException;

class HistorialLoader
{
    private const MESES = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    /** @return array{filas: array, advertencias: array} */
    public function cargar(string $ruta): array
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No existe el historial: $ruta");
        }
        $h = fopen($ruta, 'r');
        $cabecera = fgetcsv($h, 0, ',', '"', '');
        $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera[0]);

        $filas = [];
        $vistos = [];
        $adv = [];

        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $f = array_combine($cabecera, array_slice(array_pad($r, count($cabecera), ''), 0, count($cabecera)));
            $id = trim($f['id_publicacion']);

            if ($id === '') {
                $adv[] = 'Fila sin id descartada';
                continue;
            }
            if (isset($vistos[$id])) {
                $adv[] = "Fila repetida descartada: $id";
                continue;
            }
            $vistos[$id] = true;

            $fecha = $this->fecha($f['fecha']);
            if ($fecha === null) {
                $adv[] = "$id: fecha ilegible '{$f['fecha']}', fila descartada";
                continue;
            }

            $impresiones = $this->entero($f['impresiones']);
            $reacciones = $this->entero($f['reacciones']);
            $comentarios = $this->entero($f['comentarios']);
            $clics = $this->entero($f['clics']);

            if ($comentarios === null) {
                $adv[] = "$id: comentarios vacío, se toma como 0";
                $comentarios = 0;
            }

            $tasa = null;
            if ($impresiones > 0 && $reacciones !== null && $clics !== null) {
                $tasa = ($reacciones + $comentarios + $clics) / $impresiones;
            } else {
                $adv[] = "$id: no se pudo calcular la tasa de interacción";
            }

            $filas[] = [
                'id' => $id,
                'fecha' => $fecha,
                'hora' => substr(trim($f['hora']), 0, 5),
                'canal' => mb_strtolower(trim($f['canal'])),
                'formato' => mb_strtolower(trim($f['formato'])),
                'tema' => mb_strtolower(trim($f['tema'])),
                'palabras' => $this->entero($f['palabras']),
                'tiene_cifra' => $this->booleano($f['tiene_cifra']),
                'impresiones' => $impresiones,
                'reacciones' => $reacciones,
                'comentarios' => $comentarios,
                'clics' => $clics,
                'tasa' => $tasa,
            ];
        }
        fclose($h);
        return ['filas' => $filas, 'advertencias' => $adv];
    }

    private function fecha(string $texto): ?CarbonImmutable
    {
        $t = mb_strtolower(trim($texto));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
            return $this->crear($m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $t, $m)) {
            return $this->crear($m[3], $m[2], $m[1]);   // día/mes/año
        }
        if (preg_match('/^(\d{1,2})-([a-z]{3,4})-(\d{4})$/', $t, $m) && isset(self::MESES[$m[2]])) {
            return $this->crear($m[3], self::MESES[$m[2]], $m[1]);
        }
        return null;
    }

    private function crear(int|string $a, int|string $m, int|string $d): ?CarbonImmutable
    {
        return checkdate((int) $m, (int) $d, (int) $a)
            ? CarbonImmutable::create((int) $a, (int) $m, (int) $d, 0, 0, 0)
            : null;
    }

    private function entero(string $v): ?int
    {
        $v = trim($v);
        return is_numeric($v) ? (int) $v : null;
    }

    private function booleano(string $v): ?bool
    {
        $v = strtr(mb_strtolower(trim($v)), ['í' => 'i']);
        return match ($v) {
            '1', 'si', 'true' => true,
            '0', 'no', 'false' => false,
            default => null,
        };
    }
}