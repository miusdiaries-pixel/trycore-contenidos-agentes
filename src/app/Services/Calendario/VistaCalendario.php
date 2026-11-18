<?php

namespace App\Services\Calendario;

use DateTimeImmutable;

/** Genera la página web (un solo archivo HTML) con la vista semanal del calendario. */
class VistaCalendario
{
    private const PALETA = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2', '#be185d', '#4d7c0f'];
    private const SEMAFORO = ['verde' => '#15803d', 'amarillo' => '#a16207', 'rojo' => '#b91c1c'];
    private const ESTADOS = [
        'pendiente_de_aprobacion' => 'Pendiente de aprobación',
        'requiere_revision' => 'Requiere revisión',
        'programada' => 'Ya agendada',
    ];
    private const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];

    public function html(array $res, array $ocupado, array $config, DateTimeImmutable $inicio, DateTimeImmutable $fin, array $contexto): string
    {
        $colores = [];
        foreach ($config['matriz_formatos'] as $i => $f) {
            $colores[$f['id']] = self::PALETA[$i % count(self::PALETA)];
        }
        $festivos = array_column($config['calendario']['festivos'], 'nombre', 'fecha');

        $porDia = [];
        foreach ($ocupado as $o) {
            $porDia[$o['inicio']->format('Y-m-d')][] = [
                'hora' => $o['inicio']->format('H:i'), 'formato' => $o['formato'], 'tema' => $o['tema'],
                'titulo' => $o['titulo'] ?? '', 'estado' => 'programada', 'existente' => true, 'puntaje' => null,
            ];
        }
        foreach ($res['programadas'] as $p) {
            $porDia[$p['fecha']][] = [
                'hora' => $p['hora'], 'formato' => $p['formato'], 'tema' => $p['tema'], 'titulo' => $p['titulo'],
                'estado' => $p['estado'], 'existente' => false, 'puntaje' => $p['puntaje'],
            ];
        }

        $semanas = '';
        $lunes = $inicio->modify('monday this week');
        for ($l = $lunes; $l <= $fin; $l = $l->modify('+7 days')) {
            $semanas .= '<h2>Semana del ' . $l->format('d/m/Y') . '</h2><div class="semana">';
            for ($k = 0; $k < 5; $k++) {
                $d = $l->modify("+$k days");
                $f = $d->format('Y-m-d');
                $fuera = $d < $inicio || $d > $fin;
                $clase = 'dia' . ($fuera ? ' fuera' : '') . (isset($festivos[$f]) ? ' festivo' : '');
                $semanas .= "<div class=\"$clase\"><div class=\"cab\">" . self::DIAS[$k] . ' ' . $d->format('d/m')
                    . (isset($festivos[$f]) ? ' · <b>Festivo: ' . $this->e($festivos[$f]) . '</b>' : '') . '</div>';
                $items = $porDia[$f] ?? [];
                usort($items, fn ($a, $b) => $a['hora'] <=> $b['hora']);
                foreach ($items as $it) {
                    $semanas .= $this->tarjeta($it, $colores[$it['formato']] ?? '#6b7280');
                }
                $semanas .= '</div>';
            }
            $semanas .= '</div>';
        }

        $leyenda = '';
        foreach ($colores as $id => $c) {
            $leyenda .= "<span class=\"ley\"><i style=\"background:$c\"></i>" . $this->e($id) . '</span>';
        }
        $optF = '<option value="">Todos los formatos</option>';
        foreach (array_keys($colores) as $id) {
            $optF .= '<option>' . $this->e($id) . '</option>';
        }
        $optE = '<option value="">Todos los estados</option>';
        foreach (self::ESTADOS as $k => $v) {
            $optE .= '<option value="' . $k . '">' . $v . '</option>';
        }

        $sin = '';
        foreach ($res['no_ubicadas'] as $p) {
            $sin .= '<li><b>' . $this->e($p['formato']) . '</b> · ' . $this->e($p['titulo']) . '<br><span>' . $this->e($p['motivo']) . '</span></li>';
        }
        $conf = '';
        foreach ($res['conflictos_existentes'] as $c) {
            $conf .= '<li>' . $this->e($c) . '</li>';
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Calendario de contenidos</title><style>' . self::css() . '</style></head><body><header>'
            . '<h1>Calendario de contenidos</h1><p>' . $this->e($contexto['titulo']) . ' · ventana ' . $inicio->format('d/m/Y') . ' a ' . $fin->format('d/m/Y')
            . ' · tema detectado: <b>' . $this->e($contexto['tema']) . '</b></p>'
            . '<p class="aviso">Nada se publica: todas las piezas esperan aprobación humana.</p></header><main>'
            . '<div class="barra"><select id="ff">' . $optF . '</select><select id="fe">' . $optE . '</select>' . $leyenda . '</div>'
            . $semanas
            . '<section class="rojo"><h2>No se pudieron ubicar (' . count($res['no_ubicadas']) . ')</h2><ul>' . ($sin ?: '<li>Todas las piezas tienen lugar.</li>') . '</ul></section>'
            . '<section class="rojo"><h2>Conflictos en lo ya agendado (' . count($res['conflictos_existentes']) . ')</h2><ul>' . ($conf ?: '<li>Ninguno.</li>') . '</ul></section>'
            . '</main><script>' . self::js() . '</script></body></html>';
    }

    private function tarjeta(array $it, string $color): string
    {
        $p = $it['puntaje'];
        $badge = $p ? '<span class="pt" style="background:' . self::SEMAFORO[$p['semaforo']] . '">' . $p['puntaje'] . '</span>' : '';
        $estado = self::ESTADOS[$it['estado']] ?? $it['estado'];
        return '<div class="card' . ($it['existente'] ? ' ex' : '') . '" style="border-left-color:' . $color . '" data-f="' . $this->e($it['formato']) . '" data-e="' . $this->e($it['estado']) . '">'
            . '<div class="top"><b>' . $it['hora'] . '</b>' . $badge . '</div>'
            . '<div class="fmt" style="color:' . $color . '">' . $this->e($it['formato']) . '</div>'
            . '<div class="tit">' . $this->e(mb_strimwidth($it['titulo'], 0, 90, '…')) . '</div>'
            . '<div class="meta">Tema: ' . $this->e($it['tema']) . ' · ' . $this->e($estado) . '</div></div>';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function js(): string
    {
        return 'const ff=document.getElementById("ff"),fe=document.getElementById("fe");function f(){document.querySelectorAll(".card").forEach(c=>{'
            . 'c.style.display=(!ff.value||c.dataset.f===ff.value)&&(!fe.value||c.dataset.e===fe.value)?"":"none"})}ff.onchange=fe.onchange=f;';
    }

    private static function css(): string
    {
        return 'body{font:14px system-ui,sans-serif;margin:0;background:#f8fafc;color:#0f172a}header{padding:16px 24px;background:#0f172a;color:#fff}header h1{margin:0 0 4px;font-size:20px}header p{margin:2px 0}'
            . '.aviso{color:#fcd34d}main{padding:16px 24px}.barra{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:8px}select{padding:4px}'
            . '.ley{display:inline-flex;align-items:center;gap:4px;font-size:12px}.ley i{width:12px;height:12px;display:inline-block;border-radius:2px}'
            . 'h2{font-size:16px;margin:20px 0 8px}.semana{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}'
            . '.dia{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:6px;min-height:90px}.dia.fuera{opacity:.5}.dia.festivo{background:#fef3c7}'
            . '.cab{font-weight:600;font-size:12px;margin-bottom:6px}.card{border:1px solid #e2e8f0;border-left:5px solid;border-radius:4px;padding:5px 6px;margin-bottom:6px;background:#fff;font-size:12px}'
            . '.card.ex{background:#f1f5f9;border-style:dashed;border-left-style:solid}.top{display:flex;justify-content:space-between;align-items:center}'
            . '.pt{color:#fff;border-radius:10px;padding:1px 7px;font-weight:700}.fmt{font-weight:600}.tit{margin:2px 0}.meta{color:#475569;font-size:11px}'
            . '.rojo{background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:4px 14px;margin-top:16px}.rojo li{margin:6px 0}.rojo span{color:#b91c1c}'
            . '@media(max-width:900px){.semana{grid-template-columns:1fr}}';
    }
}
