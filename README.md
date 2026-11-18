# trycore-contenidos-agentes

Sistema que recibe una pieza madre (URL), la diagnostica, genera piezas por formato, las programa en un calendario y las puntúa.

## Instalación (primera vez)

1. `Copy-Item .env.example .env` y pon contraseñas de ejemplo.
2. `docker compose up -d --build`
3. `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 app composer install`
4. `docker compose exec app php artisan key:generate`
5. `docker compose exec app php artisan migrate`