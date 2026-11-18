<?php

namespace App\Console\Commands;

use App\Services\Diagnostico\ContenidoExtractor;
use App\Services\Diagnostico\DiagnosticoService;
use App\Services\Diagnostico\PiezaMadreFetcher;
use App\Services\Entradas\ConfigLoader;
use Illuminate\Console\Command;

class MarketingDiagnostico extends Command
{
    protected $signature = 'marketing:diagnostico
        {--url= : URL de la pieza madre (por defecto, la de url.txt)}
        {--base=/datos/entradas : Carpeta de entradas}
        {--salida=/resultados : Carpeta de resultados}';

    protected $description = 'Descarga la pieza madre y genera su ficha de diagnóstico';

    public function handle(
        ConfigLoader $cfg,
        PiezaMadreFetcher $fetcher,
        ContenidoExtractor $extractor,
        DiagnosticoService $diagnostico,
    ): int {
        $base = rtrim($this->option('base'), '/');
        $salida = rtrim($this->option('salida'), '/');
        $config = $cfg->cargar("$base/configuracion/config.json");

        $archivoUrl = "$base/pieza_madre/url.txt";
        $urlRespaldo = is_file($archivoUrl) ? trim(file_get_contents($archivoUrl)) : null;
        $url = $this->option('url') ?: $urlRespaldo;
        if (!$url) {
            $this->error('Falta la URL de la pieza madre (use --url o un url.txt).');
            return self::FAILURE;
        }
        $respaldo = glob("$base/pieza_madre/*.html")[0] ?? null;

        try {
            $fuente = $fetcher->obtener($url, $respaldo, $urlRespaldo);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $contenido = $extractor->extraer($fuente['html']);
        unset($fuente['html']);
        $ficha = $diagnostico->diagnosticar($contenido, $config, $fuente);

        if (!is_dir($salida)) {
            mkdir($salida, 0775, true);
        }
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents("$salida/contenido_pieza_madre.json", json_encode($contenido, $json));
        file_put_contents("$salida/diagnostico.json", json_encode($ficha, $json));

        $this->info("Pieza madre: {$ficha['titulo']}");
        $this->line("Fuente: {$fuente['origen']} ({$fuente['url']})");
        $this->newLine();
        $this->table(['Indicador', 'Valor'], array_map(
            fn ($k, $v) => [$k, is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v],
            array_keys($ficha['indicadores']),
            $ficha['indicadores'],
        ));
        $this->table(['Formato', 'Apto', 'Razón'], array_map(
            fn ($f) => [$f['formato'], $f['apto'] === null ? '?' : ($f['apto'] ? 'sí' : 'no'), $f['razon']],
            $ficha['aptitud_formatos'],
        ));
        foreach ($ficha['alertas'] as $a) {
            $this->line("[{$a['nivel']}] {$a['mensaje']}");
        }
        $this->info("Resultados en $salida/diagnostico.json");
        return self::SUCCESS;
    }
}
