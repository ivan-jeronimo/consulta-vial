# Consulta Vial — MVP

```
backend/          Laravel 13 (API + panel admin + workers de ingesta)
docker/           Dockerfile PHP 8.3, nginx, init de Postgres
frontend/         Chat vial (marca blanca)
docker-compose.yml  app (php-fpm) · nginx :8000 (API y panel) · frontend :5173 · worker (cola "ingesta") · postgres pgvector:pg16 · redis
```

## Arranque

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate      # solo la primera vez
docker compose exec app php artisan migrate --seed
```

Configura en `backend/.env`:

- `ADMIN_API_TOKEN`: token Bearer de la API de administración.
- `EMBEDDINGS_PROVIDER`: `openai` | `gemini` | `fake` (`fake` sirve para probar sin API key) y su `*_API_KEY`.
- `EMBEDDINGS_DIMENSIONS`: queda fijada en la columna `vector(N)`. Si la cambias, tienes que migrar y volver a procesar.

Si cambias `.env`, reinicia el worker: `docker compose restart worker`.

## Panel de administración (Filament)

`http://localhost:8000/admin`. Para crear un administrador:

```bash
docker compose exec app php artisan make:filament-user
```

Desde el panel puedes:

- Subir reglamentos (archivo o texto pegado) eligiendo nivel, estado y municipio. Si el municipio no existe, se crea en el mismo formulario.
- Ver el avance del procesamiento, que se refresca solo.
- Derogar, re-procesar o eliminar.
- Revisar los artículos indexados de cada documento.
- Administrar municipios.

Al elegir "Reemplaza a", el documento anterior se deroga en cuanto la reforma termina de procesarse.

## Frontend público (`frontend/`)

React + TypeScript + Vite 6 + Tailwind 4. Arranca con el resto del stack (`docker compose up -d`) en **http://localhost:5173**, con recarga en vivo. `/api` se reenvía a Laravel.

- Las dependencias viven en un volumen de Docker. Para agregar una: `docker compose exec frontend npm install <paquete>`.
- Para probarlo desde el celular en la misma red, abre `http://<IP de tu PC>:5173`.
- Build de producción: `docker compose exec frontend npm run build` (genera `dist/`).
- Ver errores de Vite: `docker compose logs -f frontend`.

## Consulta del bot

`POST /api/consulta` (público, 20 peticiones por minuto por IP):

```json
{ "municipio": "Oaxaca de Juárez", "estado": "Oaxaca", "pregunta": "¿Se permite la vuelta a la derecha continua con flecha roja?" }
```

También acepta `municipio_id` y `estado_id`. Solo con `estado`, busca en la normativa estatal y federal.

Cómo funciona:

1. **Búsqueda híbrida.** Combina texto completo en español (sin acentos, ordenado por cuántos términos de la pregunta contiene cada artículo) con búsqueda vectorial, y fusiona ambos resultados con RRF.
2. **Respuesta.** El LLM (`LLM_PROVIDER`) redacta la respuesta citando los artículos. Sin API key, el endpoint devuelve solo las `fuentes`.

Con `EMBEDDINGS_PROVIDER=fake` solo se usa la búsqueda de texto.

## Tests

```bash
docker compose exec app php artisan test     # usa la BD consulta_vial_test, embeddings fake y cola sync
```

## API de administración (`Authorization: Bearer $ADMIN_API_TOKEN`)

| Método | Ruta | |
|---|---|---|
| GET | `/api/admin/estados` · `/api/admin/estados/{id}/municipios` | catálogos |
| POST | `/api/admin/estados/{id}/municipios` | alta de municipio |
| GET | `/api/admin/reglamentos?nivel=&estado_id=&municipio_id=&estatus_vigencia=` | listado |
| POST | `/api/admin/reglamentos` (multipart) | `titulo, nivel, estado_id, municipio_id, anio, fecha_publicacion, reemplaza_a_id` + `archivo` (pdf/docx/txt/md) **o** `texto` → 202 y job en cola |
| PATCH | `/api/admin/reglamentos/{id}` | metadatos / `estatus_vigencia=derogado` (efecto inmediato) |
| POST | `/api/admin/reglamentos/{id}/reprocesar` | vuelve a encolar la ingesta |
| DELETE | `/api/admin/reglamentos/{id}` | borra el documento, sus vectores (en cascada) y el archivo |

## Flujo de ingesta (`App\Jobs\ProcesarReglamentoJob`)

`ExtractorTexto` → `NormalizadorTexto` (quita encabezados/pies repetidos y números de página, une líneas partidas) →
`SegmentadorArticulos` (Título > Capítulo > Sección > Artículo; los artículos largos se parten por fracciones) →
`EmbeddingService` (por lotes) → reemplazo atómico en `reglamentos_articulos` (índice HNSW por coseno).

Para consultar desde el bot:
`ReglamentoArticulo::aplicableA($estadoId, $municipioId)->whereVectorSimilarTo('embedding', $vector)`
devuelve la normativa federal, estatal y municipal aplicable, solo de documentos vigentes y ya procesados.


## API de fuentes (`Authorization: Bearer <token de cliente>`)

Solo lectura del texto de la normativa vigente, para sistemas cliente. Cada cliente tiene su token en `FUENTES_API_TOKENS` (separados por coma), así se puede revocar uno sin afectar a los demás. No da acceso a la API de administración.

| Método | Ruta | |
|---|---|---|
| GET | `/api/fuentes/documentos` | documentos vigentes y procesados, con sus capítulos |
| GET | `/api/fuentes/documentos/{id}/fragmentos?capitulo=` | fragmentos en orden (`id, orden, capitulo, seccion, articulo, fraccion, inciso, contenido`) |

Primer cliente: el sitio de **Andantes del Asfalto** (proyecto aparte, `C:\Desarrollo\andantes`), que genera las preguntas de su simulador de examen con la guía y los reglamentos.
