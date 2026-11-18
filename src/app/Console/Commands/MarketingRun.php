<?php

namespace App\Console\Commands;

use App\Services\Calendario\CalendarioService;
use App\Services\Calendario\VistaCalendario;
use App\Services\Diagnostico\ContenidoExtractor;
use App\Services\Diagnostico\DiagnosticoService;
use App\Services\Diagnostico\PiezaMadreFetcher;
use App\Services\Entradas\CalendarioLoader;
use App\Services\Entradas\ConfigLoader;
use App\Services\Entradas\HistorialLoader;
use App\Services\Puntaje\PuntajeService;
use App\Services\Transformacion\TransformacionService;
use Illuminate\Console\Command;

class MarketingRun extends Command
{
    protected $signature = 'marketing:run
        {--url= : URL de la pieza madre (por defecto, la de url.txt)}
        {--base=/datos/entradas : Carpeta de entradas}
        {--config= : config.json (por defecto, el de la carpeta de entradas)}
        {--ventana= : ventana.json (por defecto, el de la carpeta de entradas)}
        {--historial= : CSV del historial (por defecto, el de la carpeta de entradas)}
        {--salida=/resultados : Carpeta de resultados}';

    protected $description = 'Pipeline completo: diagnóstico, transformación, puntaje, calendario y vista web';

    public function handle(
        ConfigLoader $cfg,
        CalendarioLoader $calLoader,
        HistorialLoader $histLoader,
        PiezaMadreFetcher $fetcher,
        ContenidoExtractor $extractor,
        DiagnosticoService $diagnostico,
        TransformacionService $transformacion,
        CalendarioService $calendario,
        VistaCalendario $vista,
    ): int {
        $base = rtrim($this->option('base'), '/');
        $salida = rtrim($this->option('salida'), '/');
        try {
            // 1. Entradas
            $config = $cfg->cargar($this->option('config') ?: "$base/configuracion/config.json");
            $zona = $config['calendario']['zona_horaria'];
            $ventana = $calLoader->ventana($this->option('ventana') ?: "$base/calendario/ventana.json", $zona);
            $ocupado = $calLoader->ocupado("$base/calendario/calendario_ocupado.csv", $zona);
            $hist = $histLoader->cargar($this->option('historial') ?: "$base/historial/historial_publicaciones.csv");

            // 2. Diagnóstico
            $archivoUrl = "$base/pieza_madre/url.txt";
            $urlRespaldo = is_file($archivoUrl) ? trim(file_get_contents($archivoUrl)) : null;
            $url = $this->option('url') ?: $urlRespaldo;
            if (!$url) {
                $this->error('Falta la URL de la pieza madre (use --url o un url.txt).');
                return self::FAILURE;
            }
            $fuente = $fetcher->obtener($url, glob("$base/pieza_madre/*.html")[0] ?? null, $urlRespaldo);
            $madre = $extractor->extraer($fuente['html']);
            unset($fuente['html']);
            $ficha = $diagnostico->diagnosticar($madre, $config, $fuente);

            // 3. Transformación y validación
            $tr = $transformacion->transformar($madre, $ficha, $config, array_column($hist['filas'], 'tema'), $ventana['inicio']->format('Ym'));

            // 4. Puntaje
            $puntaje = new PuntajeService($hist['filas']);
            $piezas = array_map(function ($p) use ($puntaje) {
                $p['puntaje'] = $puntaje->puntuar($p['formato'], $p['tema'], null);
                return $p;
            }, $tr['piezas']);

            // 5. Calendario
            $res = $calendario->programar(
                $piezas, $ocupado, $ventana['inicio'], $ventana['fin'], $config,
                fn ($p, $franja) => $puntaje->puntuar($p['formato'], $p['tema'], $franja)
            );
        } catch (\Throwable $e) {
            $this->error('No se pudo completar el proceso: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 6. Resultados en archivos
        if (!is_dir($salida)) {
            mkdir($salida, 0775, true);
        }
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $fmt = fn ($p) => array_merge($p, isset($p['inicio']) ? ['inicio' => $p['inicio']->format('c')] : []);
        $existentes = array_map(fn ($o) => array_merge($o, ['inicio' => $o['inicio']->format('c')]), $ocupado);

        file_put_contents("$salida/contenido_pieza_madre.json", json_encode($madre, $json));
        file_put_contents("$salida/diagnostico.json", json_encode($ficha, $json));
        file_put_contents("$salida/piezas.json", json_encode([
            'tema' => $tr['tema'],
            'piezas' => array_map($fmt, $res['programadas']),
            'no_ubicadas' => $res['no_ubicadas'],
            'omitidos' => $tr['omitidos'],
        ], $json));
        file_put_contents("$salida/calendario.json", json_encode([
            'ventana' => ['inicio' => $ventana['inicio']->format('Y-m-d'), 'fin' => $ventana['fin']->format('Y-m-d')],
            'ya_agendadas' => $existentes,
            'programadas' => array_map(fn ($p) => [
                'id' => $p['id'], 'fecha' => $p['fecha'], 'hora' => $p['hora'], 'formato' => $p['formato'], 'tema' => $p['tema'],
                'titulo' => $p['titulo'], 'estado' => $p['estado'], 'puntaje' => $p['puntaje'],
            ], $res['programadas']),
            'no_ubicadas' => array_map(fn ($p) => ['id' => $p['id'], 'formato' => $p['formato'], 'motivo' => $p['motivo']], $res['no_ubicadas']),
            'conflictos_en_lo_ya_agendado' => $res['conflictos_existentes'],
            'analisis_historial_por_formato' => $puntaje->resumenPorFormato(),
            'advertencias_historial' => $hist['advertencias'],
        ], $json));
        file_put_contents("$salida/calendario.html", $vista->html(
            $res, $ocupado, $config, $ventana['inicio'], $ventana['fin'], ['titulo' => $ficha['titulo'], 'tema' => $tr['tema']]
        ));

        $this->info("Pieza madre: {$ficha['titulo']} (fuente: {$fuente['origen']})");
        $this->table(['Pieza', 'Fecha', 'Hora', 'Puntaje', 'Semáforo', 'Confianza', 'Estado'], array_map(fn ($p) => [
            $p['id'], $p['fecha'], $p['hora'], $p['puntaje']['puntaje'], $p['puntaje']['semaforo'], $p['puntaje']['confianza'], $p['estado'],
        ], $res['programadas']));
        foreach ($res['no_ubicadas'] as $p) {
            $this->warn("No ubicada: {$p['id']} ({$p['motivo']})");
        }
        foreach ($tr['omitidos'] as $o) {
            $this->line("Formato omitido: {$o['formato']} - {$o['motivo']}");
        }
        foreach ($ficha['alertas'] as $a) {
            $this->line("[{$a['nivel']}] {$a['mensaje']}");
        }
        $this->info("Resultados en $salida (abre calendario.html en el navegador)");
        return self::SUCCESS;
    }
}
