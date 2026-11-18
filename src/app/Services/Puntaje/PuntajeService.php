<?php

namespace App\Services\Puntaje;

/**
 * Puntaje de éxito de 0 a 100 a partir del historial.
 *
 * Para una pieza (formato, tema, franja) se toma la MEDIANA de la tasa de interacción de las
 * publicaciones pasadas con ese formato, ese tema y esa franja. La mediana resiste valores
 * atípicos (una publicación tuvo 55.397 impresiones). Cada mediana se acerca a la mediana global
 * cuando el grupo tiene pocos datos (encogimiento con K = 5). Las tres se combinan con pesos y el
 * resultado se convierte en percentil frente a lo que el mismo modelo da para el historial.
 */
class PuntajeService
{
    private const K = 5;
    private const PESOS_CON_FRANJA = ['formato' => 0.5, 'tema' => 0.3, 'franja' => 0.2];
    private const PESOS_SIN_FRANJA = ['formato' => 0.6, 'tema' => 0.4];

    private array $filas;
    private float $global;
    private array $referencia = ['con' => [], 'sin' => []];

    public function __construct(array $historial)
    {
        $this->filas = array_values(array_filter($historial, fn ($r) => $r['tasa'] !== null));
        $this->global = $this->mediana(array_column($this->filas, 'tasa'));
        foreach ($this->filas as $r) {
            $this->referencia['con'][] = $this->esperado($r['formato'], $r['tema'], $r['hora'])[0];
            $this->referencia['sin'][] = $this->esperado($r['formato'], $r['tema'], null)[0];
        }
        sort($this->referencia['con']);
        sort($this->referencia['sin']);
    }

    /** @return array{puntaje: int, semaforo: string, confianza: string, tasa_esperada: float, detalle: array, nota: string} */
    public function puntuar(string $formato, string $tema, ?string $franja): array
    {
        [$e, $detalle] = $this->esperado($formato, $tema, $franja);
        $ref = $this->referencia[$franja === null ? 'sin' : 'con'];
        $menores = count(array_filter($ref, fn ($v) => $v <= $e));
        $puntaje = $ref ? (int) round(100 * $menores / count($ref)) : 0;

        $n = min($detalle['formato']['n'], $detalle['tema']['n']);
        $confianza = $n >= 8 ? 'alta' : ($n >= 4 ? 'media' : 'baja');
        $nota = $detalle['formato']['n'] === 0
            ? 'No hay historial de este formato: el puntaje se apoya solo en el tema y en el promedio global'
            : ($confianza === 'baja' ? 'Pocos datos para este formato o tema: tomar el puntaje con cautela' : '');

        return [
            'puntaje' => $puntaje,
            'semaforo' => $puntaje >= 67 ? 'verde' : ($puntaje >= 34 ? 'amarillo' : 'rojo'),
            'confianza' => $confianza,
            'tasa_esperada' => round($e, 4),
            'detalle' => $detalle,
            'nota' => $nota,
        ];
    }

    /** Medianas del historial por formato, para contrastar creencias del equipo con los datos. */
    public function resumenPorFormato(): array
    {
        $res = [];
        foreach (array_unique(array_column($this->filas, 'formato')) as $f) {
            $v = array_column(array_filter($this->filas, fn ($r) => $r['formato'] === $f), 'tasa');
            $res[$f] = ['n' => count($v), 'mediana_tasa' => round($this->mediana($v), 4)];
        }
        uasort($res, fn ($a, $b) => $b['mediana_tasa'] <=> $a['mediana_tasa']);
        return $res;
    }

    /** @return array{0: float, 1: array} */
    private function esperado(string $formato, string $tema, ?string $franja): array
    {
        $factores = ['formato' => ['formato', $formato], 'tema' => ['tema', $tema]];
        $pesos = self::PESOS_SIN_FRANJA;
        if ($franja !== null) {
            $factores['franja'] = ['hora', $franja];
            $pesos = self::PESOS_CON_FRANJA;
        }
        $e = 0.0;
        $detalle = [];
        foreach ($factores as $nombre => [$campo, $valor]) {
            $v = array_column(array_filter($this->filas, fn ($r) => $r[$campo] === $valor), 'tasa');
            $n = count($v);
            $med = $n ? $this->mediana($v) : $this->global;
            $encogida = ($n * $med + self::K * $this->global) / ($n + self::K);
            $e += $pesos[$nombre] * $encogida;
            $detalle[$nombre] = ['valor' => $valor, 'n' => $n, 'mediana' => round($med, 4)];
        }
        $detalle['mediana_global'] = round($this->global, 4);
        return [$e, $detalle];
    }

    private function mediana(array $v): float
    {
        if (!$v) {
            return 0.0;
        }
        sort($v);
        $m = intdiv(count($v), 2);
        return count($v) % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }
}
