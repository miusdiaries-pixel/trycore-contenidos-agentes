<?php

namespace App\Console\Commands;

use App\Services\Entradas\CalendarioLoader;
use App\Services\Entradas\ConfigLoader;
use App\Services\Entradas\HistorialLoader;
use Illuminate\Console\Command;

class ProbarEntradas extends Command
{
    protected $signature = 'entradas:probar {--base=/datos/entradas}';
    protected $description = 'Lee y limpia las entradas y muestra un resumen';

    public function handle(ConfigLoader $c, HistorialLoader $h, CalendarioLoader $cal): int
    {
        $base = $this->option('base');
        $config = $c->cargar("$base/configuracion/config.json");
        $zona = $config['calendario']['zona_horaria'];

        $hist = $h->cargar("$base/historial/historial_publicaciones.csv");
        $ventana = $cal->ventana("$base/calendario/ventana.json", $zona);
        $ocupado = $cal->ocupado("$base/calendario/calendario_ocupado.csv", $zona);

        $this->info('Formatos en la matriz: ' . count($config['matriz_formatos']));
        $this->info('Publicaciones únicas en el historial: ' . count($hist['filas']));
        $this->info('Advertencias de limpieza: ' . count($hist['advertencias']));
        foreach ($hist['advertencias'] as $a) {
            $this->line(" - $a");
        }
        $this->info('Ventana: ' . $ventana['inicio']->toDateString() . ' a ' . $ventana['fin']->toDateString());
        $this->info('Piezas ya agendadas: ' . count($ocupado));
        return self::SUCCESS;
    }
}