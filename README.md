# trycore-contenidos-agentes

Sistema que recibe una pieza madre (URL), la diagnostica, genera piezas por formato, las valida,
las puntúa con el historial y las programa en un calendario de dos semanas. Nada se publica:
todo queda "pendiente de aprobación" humana. Caso ficticio con fines de evaluación.

## Requisitos
Docker Desktop. Las entradas del reto van en `datos/entradas/` (no se suben al repositorio).

## Instalación (primera vez)
1. `Copy-Item .env.example .env` y poner contraseñas de ejemplo; hacer lo mismo con `src/.env`.
2. `docker compose up -d --build`
3. `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 app composer install`
4. `docker compose exec app php artisan key:generate`

## Ejecución (un solo comando)
`docker compose exec app php artisan marketing:run`
Opciones: `--url=` (otra pieza madre), `--config=`, `--ventana=`, `--historial=`, `--base=`, `--salida=`.

## Resultados (carpeta `resultados/`)
`diagnostico.json`, `piezas.json`, `calendario.json` y `calendario.html` (abrir en el navegador).

## Pruebas
`docker compose exec app php artisan test`

## Límites
Redacción por reglas (sin IA en ejecución), un solo tema por pieza madre, puntaje calculado con
60 publicaciones de LinkedIn y sin datos de newsletter, MySQL levantado pero sin uso: los resultados van a archivos.