<?php

namespace App\Services\Calendario;

use App\Support\Texto;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Programa las piezas en la ventana. Algoritmo voraz: las piezas de mayor puntaje primero, cada una
 * en el primer espacio (día + franja) que cumpla todas las reglas. Lo ya agendado cuenta para la
 * capacidad y para la separación por tema. Lo que no cabe queda "no ubicado", con el motivo.
 */
class CalendarioService
{
    private const DIAS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    /**
     * @param array $piezas piezas con 'validacion', 'formato', 'tema' y 'puntaje' (arreglo del PuntajeService)
     * @param array $ocupado piezas ya agendadas, con 'inicio' (DateTimeImmutable), 'formato' y 'tema'
     * @param callable $repuntuar fn(array $pieza, string $franja): array  nuevo puntaje con la franja real
     */
    public function programar(array $piezas, array $ocupado, DateTimeImmutable $inicio, DateTimeImmutable $fin, array $config, callable $repuntuar): array
    {
        $cal = $config['calendario'];
        $zona = new DateTimeZone($cal['zona_horaria']);
        $maxFormato = [];
        foreach ($config['matriz_formatos'] as $f) {
            $maxFormato[$f['id']] = $f['max_por_semana'] ?? PHP_INT_MAX;
        }

        $slots = $this->espacios($inicio, $fin, $cal, $zona);
        $colocadas = $ocupado;
        $programadas = [];
        $noUbicadas = [];

        usort($piezas, fn ($a, $b) => [$b['puntaje']['puntaje'], $a['id']] <=> [$a['puntaje']['puntaje'], $b['id']]);

        foreach ($piezas as $p) {
            if (!$p['validacion']['ok']) {
                $noUbicadas[] = $this->sinLugar($p, 'No pasó la validación: debe corregirse antes de programarla');
                continue;
            }
            $motivos = [];
            $elegido = null;
            foreach ($slots as $s) {
                $fallo = $this->incumple($s, $p, $colocadas, $cal, $maxFormato);
                if ($fallo === null) {
                    $elegido = $s;
                    break;
                }
                $motivos[$fallo] = ($motivos[$fallo] ?? 0) + 1;
            }
            if ($elegido === null) {
                arsort($motivos);
                $noUbicadas[] = $this->sinLugar($p, $motivos ? array_key_first($motivos) : 'No hay espacios disponibles en la ventana');
                continue;
            }
            $franja = $elegido->format('H:i');
            $p['puntaje'] = $repuntuar($p, $franja);
            $p['inicio'] = $elegido;
            $p['fecha'] = $elegido->format('Y-m-d');
            $p['hora'] = $franja;
            $programadas[] = $p;
            $colocadas[] = ['inicio' => $elegido, 'formato' => $p['formato'], 'tema' => $p['tema']];
        }

        usort($programadas, fn ($a, $b) => $a['inicio'] <=> $b['inicio']);
        return [
            'programadas' => $programadas,
            'no_ubicadas' => $noUbicadas,
            'conflictos_existentes' => $this->conflictosExistentes($ocupado, $cal, $maxFormato),
        ];
    }

    /** Días hábiles no festivos de la ventana × franjas de publicación. */
    private function espacios(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $cal, DateTimeZone $zona): array
    {
        $habiles = array_map(fn ($d) => self::DIAS[Texto::normalizar($d)], $cal['dias_habiles']);
        $festivos = array_column($cal['festivos'], 'nombre', 'fecha');
        $slots = [];
        for ($d = $inicio; $d <= $fin; $d = $d->modify('+1 day')) {
            $fecha = $d->format('Y-m-d');
            if (!in_array((int) $d->format('N'), $habiles, true) || isset($festivos[$fecha])) {
                continue;
            }
            foreach ($cal['franjas_de_publicacion'] as $h) {
                $slots[] = new DateTimeImmutable("$fecha $h", $zona);
            }
        }
        return $slots;
    }

    /** Primera regla que se rompería al poner la pieza en ese espacio; null si todo se cumple. */
    private function incumple(DateTimeImmutable $s, array $p, array $colocadas, array $cal, array $maxFormato): ?string
    {
        $dia = $s->format('Y-m-d');
        $semana = $s->format('o-W');
        $enDia = $enSemana = $enFormato = 0;
        foreach ($colocadas as $c) {
            if ($c['inicio']->getTimestamp() === $s->getTimestamp()) {
                return 'Franja ya ocupada';
            }
            $mismaSemana = $c['inicio']->format('o-W') === $semana;
            $enDia += $c['inicio']->format('Y-m-d') === $dia ? 1 : 0;
            $enSemana += $mismaSemana ? 1 : 0;
            $enFormato += $mismaSemana && $c['formato'] === $p['formato'] ? 1 : 0;
        }
        if ($enDia >= $cal['capacidad_max_por_dia']) {
            return 'Capacidad diaria agotada';
        }
        if ($enSemana >= $cal['capacidad_max_por_semana']) {
            return 'Capacidad semanal agotada';
        }
        if ($enFormato >= $maxFormato[$p['formato']]) {
            return 'Máximo semanal de este formato alcanzado';
        }
        $minimo = $cal['separacion_minima_mismo_tema_horas'] * 3600;
        foreach ($colocadas as $c) {
            if ($c['tema'] === $p['tema'] && abs($c['inicio']->getTimestamp() - $s->getTimestamp()) < $minimo) {
                return "Separación mínima de {$cal['separacion_minima_mismo_tema_horas']} h con otra pieza del mismo tema";
            }
        }
        return null;
    }

    private function sinLugar(array $p, string $motivo): array
    {
        $p['motivo'] = $motivo;
        return $p;
    }

    /** Revisa si lo que ya estaba agendado rompe alguna regla, para resaltarlo en la vista. */
    private function conflictosExistentes(array $ocupado, array $cal, array $maxFormato): array
    {
        $habiles = array_map(fn ($d) => self::DIAS[Texto::normalizar($d)], $cal['dias_habiles']);
        $festivos = array_column($cal['festivos'], 'nombre', 'fecha');
        $conf = [];
        foreach ($ocupado as $i => $o) {
            $etq = $o['formato'] . ' del ' . $o['inicio']->format('Y-m-d H:i');
            if (!in_array((int) $o['inicio']->format('N'), $habiles, true)) {
                $conf[] = "$etq: día no hábil";
            }
            if (isset($festivos[$o['inicio']->format('Y-m-d')])) {
                $conf[] = "$etq: festivo";
            }
            foreach ($ocupado as $j => $q) {
                if ($j > $i && $o['tema'] === $q['tema']
                    && abs($o['inicio']->getTimestamp() - $q['inicio']->getTimestamp()) < $cal['separacion_minima_mismo_tema_horas'] * 3600) {
                    $conf[] = "$etq y {$q['formato']} del {$q['inicio']->format('Y-m-d H:i')}: menos de {$cal['separacion_minima_mismo_tema_horas']} h entre piezas del mismo tema";
                }
            }
        }
        return $conf;
    }
}
