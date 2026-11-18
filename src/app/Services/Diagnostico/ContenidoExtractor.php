<?php

namespace App\Services\Diagnostico;

use App\Support\Texto;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Convierte el HTML de una pieza madre en contenido estructurado:
 * título, descripción, secciones (por h2), párrafos, listas y subtítulos.
 * No sabe nada de ninguna guía en particular.
 */
class ContenidoExtractor
{
    private const RUIDO = ['script', 'style', 'noscript', 'svg', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'button', 'select'];

    public function extraer(string $html): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $titulo = $this->titulo($xp);
        $descripcion = $this->meta($xp, 'description');

        foreach (self::RUIDO as $tag) {
            foreach (iterator_to_array($xp->query('//' . $tag)) as $nodo) {
                $nodo->parentNode?->removeChild($nodo);
            }
        }

        $raiz = $this->raiz($xp);
        $secciones = [];
        $listas = [];
        $listaPorPadre = [];
        $actual = null;

        foreach ($xp->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li', $raiz) as $n) {
            /** @var DOMElement $n */
            $tag = strtolower($n->nodeName);
            $texto = Texto::limpiar($n->textContent);
            if ($texto === '') {
                continue;
            }

            if ($tag === 'h1') {
                $titulo = $titulo ?: $texto;
                continue;
            }
            if ($tag === 'h2') {
                $secciones[] = $this->nuevaSeccion($texto);
                $actual = count($secciones) - 1;
                continue;
            }
            if ($actual === null) {
                $secciones[] = $this->nuevaSeccion('Introducción', true);
                $actual = 0;
            }
            if ($tag === 'h3' || $tag === 'h4') {
                $secciones[$actual]['subtitulos'][] = $texto;
                continue;
            }
            if ($tag === 'p') {
                if (!$this->dentroDe($n, 'li')) {
                    $secciones[$actual]['parrafos'][] = $texto;
                }
                continue;
            }
            // li: se ignoran los índices (solo enlaces internos) y los li que contienen otros li
            if ($xp->query('.//li', $n)->length > 0 || $this->esIndice($xp, $n, $texto)) {
                continue;
            }
            $padre = $n->parentNode;
            $id = spl_object_id($padre);
            if (!isset($listaPorPadre[$id])) {
                $listas[] = ['tipo' => strtolower($padre->nodeName), 'seccion' => $secciones[$actual]['titulo'], 'items' => []];
                $listaPorPadre[$id] = count($listas) - 1;
            }
            $listas[$listaPorPadre[$id]]['items'][] = $texto;
            $secciones[$actual]['items'][] = $texto;
        }

        foreach ($secciones as &$s) {
            $s['palabras'] = Texto::palabras(implode(' ', array_merge($s['parrafos'], $s['items'])));
        }
        unset($s);

        $texto = implode("\n", array_merge(
            [$titulo],
            ...array_map(fn ($s) => array_merge([$s['titulo']], $s['subtitulos'], $s['parrafos'], $s['items']), $secciones)
        ));

        return [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'secciones' => $secciones,
            'listas' => $listas,
            'texto' => $texto,
            'palabras' => Texto::palabras($texto),
        ];
    }

    private function nuevaSeccion(string $titulo, bool $implicita = false): array
    {
        return ['titulo' => $titulo, 'implicita' => $implicita, 'subtitulos' => [], 'parrafos' => [], 'items' => [], 'palabras' => 0];
    }

    private function titulo(DOMXPath $xp): string
    {
        foreach ($xp->query('//h1') as $h1) {
            $t = Texto::limpiar($h1->textContent);
            if ($t !== '') {
                return $t;
            }
        }
        $og = $this->meta($xp, 'og:title');
        if ($og) {
            return $og;
        }
        $t = $xp->query('//title')->item(0);
        return $t ? Texto::limpiar($t->textContent) : '';
    }

    private function meta(DOMXPath $xp, string $nombre): string
    {
        $n = $xp->query("//meta[@name='$nombre' or @property='$nombre']/@content")->item(0);
        return $n ? Texto::limpiar($n->nodeValue) : '';
    }

    /** El <main> o <article> con más texto; si no hay, el <body>. */
    private function raiz(DOMXPath $xp): DOMNode
    {
        $mejor = null;
        $largo = 0;
        foreach ($xp->query('//main|//article') as $c) {
            $l = mb_strlen($c->textContent);
            if ($l > $largo) {
                $mejor = $c;
                $largo = $l;
            }
        }
        return $mejor ?? $xp->query('//body')->item(0) ?? $xp->document->documentElement;
    }

    private function dentroDe(DOMNode $n, string $tag): bool
    {
        for ($p = $n->parentNode; $p; $p = $p->parentNode) {
            if (strtolower($p->nodeName) === $tag) {
                return true;
            }
        }
        return false;
    }

    private function esIndice(DOMXPath $xp, DOMNode $li, string $texto): bool
    {
        $a = $xp->query(".//a[starts-with(@href,'#')]", $li)->item(0);
        return $a !== null && Texto::normalizar(Texto::limpiar($a->textContent)) === Texto::normalizar($texto);
    }
}
