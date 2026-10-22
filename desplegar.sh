#!/bin/sh
# Publica lo último de main en el servidor: ./desplegar.sh
set -e
cd "$(dirname "$0")"

# git pull puede cambiar este mismo archivo mientras corre: después de bajar
# los cambios se vuelve a lanzar la versión nueva, que ya no hace pull.
if [ "$1" != "--sin-pull" ]; then
    git pull --ff-only
    exec ./desplegar.sh --sin-pull
fi

. ./.env
docker network create proxy >/dev/null 2>&1 || true

# --pull: baja la imagen base actualizada (parches de PHP y Alpine).
./dc build --pull
./dc up -d postgres redis
./dc run --rm app php artisan migrate --force
# Se recrean siempre: Compose no siempre detecta que la imagen cambió. La base y Redis no se tocan.
./dc up -d --no-deps --force-recreate app worker web
./dc up -d --remove-orphans

# El Caddy de la puerta (proyecto Andantes) pone el dominio y el HTTPS.
mkdir -p "$HOME/caddy-sitios"
sed "s/{DOMINIO}/$DOMINIO/" docker/produccion/sitio.caddy > "$HOME/caddy-sitios/consulta-vial.caddy"
docker exec andantes-web-1 caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 \
    || echo "Aviso: no se pudo recargar Caddy (¿está arriba el proyecto Andantes?)"

docker image prune -f >/dev/null
./dc ps
