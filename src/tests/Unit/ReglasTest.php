<?php

namespace Tests\Unit;

use App\Services\Calendario\CalendarioService;
use App\Services\Transformacion\Utm;
use App\Services\Transformacion\ValidadorPieza;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class ReglasTest extends TestCase
{
    private function validar(array $pieza, string $formato = 'post_linkedin'): array
    {
        $x = Fixtures::contexto();
        $f = collect_formato($x->config, $formato);
        return (new ValidadorPieza())->validar($pieza, $f, $x);
    }

    private function hay(array $lista, string $fragmento): bool
    {
        foreach ($lista as $m) {
            if (str_contains($m, $fragmento)) {
                return true;
            }
        }
        return false;
    }

    public function test_un_post_correcto_pasa_la_validacion(): void
    {
        $r = $this->validar(Fixtures::post());
        $this->assertSame([], $r['errores']);
    }

    public function test_falla_con_termino_prohibido(): void
    {
        $p = Fixtures::post(['gancho' => '¿Conoces el motor GPT?']);
        $this->assertTrue($this->hay($this->validar($p)['errores'], 'GPT'));
    }

    public function test_falla_con_nombre_confidencial(): void
    {
        $p = Fixtures::post(['llamado_a_la_accion' => 'Mira el caso de Banco Andino del Sur.']);
        $this->assertTrue($this->hay($this->validar($p)['errores'], 'Banco Andino del Sur'));
    }

    public function test_falla_con_cifra_que_no_existe_en_la_pieza_madre(): void
    {
        $p = Fixtures::post(['llamado_a_la_accion' => 'Ahorra un 87% de tiempo.']);
        $this->assertTrue($this->hay($this->validar($p)['errores'], 'no existe en la pieza madre'));
    }

    public function test_cifra_sin_fuente_en_la_madre_se_marca_para_revision(): void
    {
        $p = Fixtures::post(['llamado_a_la_accion' => 'Un 40% de ahorro.']);
        $r = $this->validar($p);
        $this->assertSame([], $r['errores']);
        $this->assertTrue($this->hay($r['revisar'], '40%'));
    }

    public function test_falla_si_el_enlace_no_tiene_utm(): void
    {
        $p = Fixtures::post(['enlace' => 'https://ejemplo.com/guia-de-prueba/']);
        $this->assertTrue($this->hay($this->validar($p)['errores'], 'utm_source'));
    }

    public function test_falla_si_el_post_se_pasa_de_largo(): void
    {
        $p = Fixtures::post(['desarrollo' => array_fill(0, 20, 'La automatización mejora procesos repetitivos en las empresas. Los equipos ganan tiempo.')]);
        $this->assertTrue($this->hay($this->validar($p)['errores'], 'caracteres'));
    }

    public function test_falla_si_el_carrusel_tiene_una_lamina_con_mas_de_30_palabras(): void
    {
        $larga = trim(str_repeat('palabra ', 31));
        $c = ['portada' => 'Portada', 'laminas' => [$larga, 'Dos', 'Tres', 'Cuatro'], 'cierre' => 'Cierre'];
        $p = ['id' => 'carrusel_linkedin-1', 'formato' => 'carrusel_linkedin', 'canal' => 'linkedin', 'numero' => 1, 'tema' => 'rpa',
            'titulo' => 'x', 'contenido' => $c, 'texto' => implode("\n", array_merge([$c['portada']], $c['laminas'], [$c['cierre']]))];
        $this->assertTrue($this->hay($this->validar($p, 'carrusel_linkedin')['errores'], '31 palabras'));
    }

    public function test_utm_en_minusculas_sin_tildes_y_con_guiones(): void
    {
        $params = Utm::params(Fixtures::config(), 'linkedin', 'post_linkedin', 2, 'guia-de-prueba', '202611');
        $this->assertSame('post-linkedin-2', $params['utm_content']);
        $this->assertSame('guia-de-prueba-202611', $params['utm_campaign']);
        $this->assertSame('social-organico', $params['utm_medium']);
    }

    // ---- Calendario ----

    private function pieza(string $id, string $tema, int $puntaje = 50, string $formato = 'post_linkedin'): array
    {
        return ['id' => $id, 'formato' => $formato, 'tema' => $tema, 'titulo' => $id,
            'validacion' => ['ok' => true], 'puntaje' => ['puntaje' => $puntaje]];
    }

    private function fecha(string $f): DateTimeImmutable
    {
        return new DateTimeImmutable($f, new DateTimeZone('America/Bogota'));
    }

    private function programar(array $piezas, array $ocupado = []): array
    {
        return (new CalendarioService())->programar(
            $piezas, $ocupado, $this->fecha('2026-11-30 00:00'), $this->fecha('2026-12-11 00:00'),
            Fixtures::config(), fn ($p, $franja) => ['puntaje' => 50]
        );
    }

    public function test_el_calendario_nunca_programa_en_fin_de_semana_ni_festivo(): void
    {
        $piezas = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $i => $t) {
            $piezas[] = $this->pieza("p$i", $t, 90 - $i, $i % 2 ? 'carrusel_linkedin' : 'post_linkedin');
        }
        $res = $this->programar($piezas);
        foreach ($res['programadas'] as $p) {
            $this->assertTrue((int) $p['inicio']->format('N') <= 5);
            $this->assertTrue($p['fecha'] !== '2026-12-08');
        }
    }

    public function test_el_calendario_respeta_el_maximo_por_semana_contando_lo_ya_agendado(): void
    {
        $ocupado = [
            ['inicio' => $this->fecha('2026-12-07 11:00'), 'formato' => 'post_linkedin', 'tema' => 'x1'],
            ['inicio' => $this->fecha('2026-12-10 08:30'), 'formato' => 'carrusel_linkedin', 'tema' => 'x2'],
        ];
        $piezas = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i => $t) {
            $piezas[] = $this->pieza("p$i", $t, 90 - $i, 'newsletter_inexistente');
        }
        // Formato sin límite propio en la matriz de prueba: solo manda la capacidad semanal de 4.
        $config = Fixtures::config();
        $config['matriz_formatos'][] = ['id' => 'newsletter_inexistente', 'canal' => 'linkedin', 'max_por_semana' => 9];
        $res = (new CalendarioService())->programar($piezas, $ocupado, $this->fecha('2026-11-30 00:00'), $this->fecha('2026-12-11 00:00'), $config, fn ($p, $f) => ['puntaje' => 50]);
        $semana2 = array_filter($res['programadas'], fn ($p) => $p['fecha'] >= '2026-12-07');
        $this->assertSame(2, count($semana2));   // 2 ya agendadas + 2 nuevas = 4
    }

    public function test_la_separacion_de_48_horas_se_mide_con_fecha_y_hora(): void
    {
        // Pieza ya agendada el lunes 30/11 a las 15:00. A las 08:30 del miércoles hay 41,5 h: no sirve.
        $ocupado = [['inicio' => $this->fecha('2026-11-30 15:00'), 'formato' => 'carrusel_linkedin', 'tema' => 'rpa']];
        $res = $this->programar([$this->pieza('nueva', 'rpa')], $ocupado);
        $this->assertSame('2026-12-02 15:00', $res['programadas'][0]['inicio']->format('Y-m-d H:i'));
    }

    public function test_lo_que_no_cabe_queda_no_ubicado_con_motivo(): void
    {
        $ocupado = [['inicio' => $this->fecha('2026-12-01 08:30'), 'formato' => 'carrusel_linkedin', 'tema' => 'rpa']];
        $piezas = [];
        for ($i = 0; $i < 6; $i++) {
            $piezas[] = $this->pieza("p$i", 'rpa', 80 - $i);   // todas del mismo tema
        }
        $res = $this->programar($piezas, $ocupado);
        $this->assertTrue(count($res['no_ubicadas']) > 0);
        $this->assertTrue(str_contains($res['no_ubicadas'][0]['motivo'], '48 h') || str_contains($res['no_ubicadas'][0]['motivo'], 'Capacidad'));
    }

    public function test_una_pieza_que_no_pasa_la_validacion_no_se_programa(): void
    {
        $p = $this->pieza('mala', 'rpa');
        $p['validacion'] = ['ok' => false];
        $res = $this->programar([$p]);
        $this->assertSame([], $res['programadas']);
        $this->assertSame(1, count($res['no_ubicadas']));
    }
}

/** Busca la ficha de un formato dentro del config. */
function collect_formato(array $config, string $id): array
{
    foreach ($config['matriz_formatos'] as $f) {
        if ($f['id'] === $id) {
            return $f;
        }
    }
    throw new \RuntimeException("Formato $id no está en la matriz");
}
