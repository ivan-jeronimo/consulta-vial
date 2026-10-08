# Consulta Vial — MVP

```
backend/          Laravel 13 (API + panel admin + workers de ingesta)
docker/           Dockerfile PHP 8.3, nginx, init de Postgres
frontend/         Chat vial (marca blanca)
sitio/            Sitio de la comunidad Andantes del Asfalto
docker-compose.yml  app (php-fpm) · nginx :8000 (API y panel) · frontend :5173 · sitio :5174 · worker (cola "ingesta") · postgres pgvector:pg16 · redis
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

## Sitio de la comunidad (`sitio/`)

Página de Andantes del Asfalto en **http://localhost:5174** (mismo esquema que `frontend/`: React + Vite + Tailwind en Docker, servicio `sitio`). El chat de `frontend/` se queda sin marca y el sitio solo lo enlaza (`URL_CHAT`, por defecto `http://localhost:5173`).

- Contenido: en el panel, grupo **Sitio Andantes** (videos destacados, dinámicas, ganadores, colaboraciones y ajustes como seguidores, lema y enlace de Facebook).
- Los ganadores solo se publican si tienen marcado "Aceptó aparecer en el sitio".
- **Sincronización** (`php artisan sitio:sincronizar`, la corre el servicio `scheduler` cada 6 horas; también hay botón "Sincronizar ahora" en Videos). Trae seguidores de Facebook, Instagram (si es profesional y está vinculado a la página; requiere `instagram_basic` en el token) y YouTube (`YOUTUBE_API_KEY` + canal en Ajustes), logo, portada, videos y reels de los últimos `FACEBOOK_MESES` (12) con sus reproducciones, y las publicaciones con `#dinamica` a una bandeja donde eliges "Crear dinámica" o "Ignorar". Respeta lo editado en el panel (título, fijado, visible). Si falla, el panel lo muestra en "Redes sociales".
- TikTok no tiene API práctica: sus seguidores se capturan a mano en Ajustes. El sitio suma todas las redes.
- Variables en `backend/.env`: `FACEBOOK_PAGE_ID` y `FACEBOOK_PAGE_TOKEN` (token de página que no caduca, sacado de la app "Andantes Contenido", que se queda en modo desarrollo). `FACEBOOK_APP_ID`/`FACEBOOK_APP_SECRET` son de la app de inicio de sesión.
- API: `GET /api/sitio` devuelve todo el contenido en una sola respuesta. Las imágenes se sirven en `/storage/...`, así que necesitas `php artisan storage:link` una sola vez.

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

## Reto del asfalto (simulador del examen de manejo)

En el sitio, sección "El reto". Se entra con Facebook (app "Andantes del Asfalto": `FACEBOOK_APP_ID`/`FACEBOOK_APP_SECRET`); la app debe tener `SITIO_URL/auth/facebook/callback` en "URI de redireccionamiento de OAuth válidos".

- **Preguntas:** panel "Reto > Preguntas". "Generar preguntas" (o `php artisan reto:generar-preguntas --lotes=N`) usa el LLM sobre la guía de examen o un reglamento y deja borradores; solo se juegan las **aprobadas** (mínimo 5).
- **Partida:** 10 preguntas al azar, 20 s cada una; 100 puntos por acierto + hasta 50 por rapidez. El servidor guarda la respuesta correcta, el orden de las opciones y el tiempo.
- **Ranking mensual:** la mejor partida del mes de cada jugador que aceptó aparecer con su nombre y foto.
- **Privacidad:** el jugador puede ocultarse del ranking y "Borrar mis datos" (cuenta, partidas y foto). En el panel, "Reto > Jugadores".
