#!/bin/sh
# Respaldo diario de la base (artículos, embeddings, consultas) y de los PDF de reglamentos.
# Guarda 14 días en ~/respaldos/consulta-vial.
set -e
cd "$(dirname "$0")/.."
destino="$HOME/respaldos/consulta-vial"
fecha=$(date +%F)
mkdir -p "$destino"

./dc exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$destino/base-$fecha.dump"
./dc exec -T app tar -czf - -C storage/app private > "$destino/archivos-$fecha.tar.gz"

find "$destino" -type f -mtime +14 -delete
