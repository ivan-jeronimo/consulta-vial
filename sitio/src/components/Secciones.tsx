import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import type { Colaboracion, Dinamica, EstatusDinamica, Ganador, Video } from '../api'
import { compacto, fecha, iniciales } from '../formato'

// El chat vial vive aparte (frontend/, marca blanca); aquí solo se enlaza.
const URL_CHAT: string = import.meta.env.VITE_URL_CHAT ?? 'http://localhost:5173'

function Seccion({ id, titulo, acciones, children, oscura = false }: { id: string; titulo: string; acciones?: ReactNode; children: ReactNode; oscura?: boolean }) {
  return (
    <section id={id} className={`scroll-mt-14 ${oscura ? 'bg-asfalto-900 text-white' : ''}`}>
      <div className="mx-auto max-w-5xl px-4 py-12">
        <div className="flex items-end justify-between gap-4">
          <h2 className="text-2xl sm:text-3xl font-black tracking-tight">{titulo}</h2>
          {acciones}
        </div>
        <div className="mt-6">{children}</div>
      </div>
    </section>
  )
}

const Triangulo = ({ className = 'size-6' }: { className?: string }) => (
  <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden><path d="M8 5v14l11-7z" /></svg>
)

/** Miniatura con botón de play; el reproductor de Facebook (pesado) solo se carga al tocarla. */
export function Reproductor({ video, etiqueta }: { video: Video; etiqueta?: ReactNode }) {
  const [reproducir, setReproducir] = useState(false)

  // Formato vertical para todos: la mayoría son reels y así todo queda parejo.
  return (
    <div className="relative aspect-[3/4] overflow-hidden bg-asfalto-900">
      {reproducir ? (
        <iframe
          src={video.embed}
          title={video.titulo}
          className="absolute inset-0 size-full"
          allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
          allowFullScreen
        />
      ) : (
        <button type="button" onClick={() => setReproducir(true)} className="group absolute inset-0 size-full" aria-label={`Reproducir: ${video.titulo}`}>
          {video.miniatura && <img src={video.miniatura} alt="" loading="lazy" className="size-full object-cover" />}
          {etiqueta && (
            <span className="absolute left-2 top-2 rounded-full bg-asfalto-900/80 px-2 py-0.5 text-xs font-bold text-linea">{etiqueta}</span>
          )}
          {video.vistas != null && (
            <span className="absolute bottom-2 left-2 flex items-center gap-1 rounded-full bg-asfalto-900/80 px-2 py-0.5 text-xs font-semibold text-white">
              <Triangulo className="size-3.5" />
              {compacto(video.vistas)}
            </span>
          )}
          <span className="absolute inset-0 grid place-items-center">
            <span className="grid size-14 place-items-center rounded-full bg-linea text-asfalto-900 shadow-lg transition group-hover:scale-110">
              <Triangulo className="size-6 translate-x-0.5" />
            </span>
          </span>
        </button>
      )}
    </div>
  )
}

/**
 * Carrusel con scroll nativo (se desliza con el dedo) y flechas que avanzan
 * de grupo en grupo: 4 por vista en escritorio, 3 en tableta y 2 en celular.
 */
function useCarrusel() {
  const lista = useRef<HTMLUListElement>(null)
  const [estado, setEstado] = useState({ pagina: 0, paginas: 1, porPagina: 1, paso: 0 })

  const medir = useCallback(() => {
    const el = lista.current
    const primero = el?.firstElementChild as HTMLElement | null
    if (!el || !primero) return

    const separacion = parseFloat(getComputedStyle(el).columnGap) || 0
    const paso = primero.offsetWidth + separacion
    const porPagina = Math.max(1, Math.round((el.clientWidth + separacion) / paso))
    const paginas = Math.ceil(el.children.length / porPagina)
    const alFinal = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2
    const pagina = alFinal ? paginas - 1 : Math.round(el.scrollLeft / (porPagina * paso))

    setEstado((e) => (e.pagina === pagina && e.paginas === paginas && e.porPagina === porPagina && e.paso === paso ? e : { pagina, paginas, porPagina, paso }))
  }, [])

  useEffect(() => {
    const el = lista.current
    if (!el) return
    medir()
    const observador = new ResizeObserver(medir)
    observador.observe(el)
    return () => observador.disconnect()
  }, [medir])

  const ir = (pagina: number) => {
    const destino = Math.max(0, Math.min(estado.paginas - 1, pagina))
    lista.current?.scrollTo({ left: destino * estado.porPagina * estado.paso, behavior: 'smooth' })
  }

  return { lista, medir, ir, ...estado }
}

function Flecha({ direccion, deshabilitada, onClick }: { direccion: 'anterior' | 'siguiente'; deshabilitada: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={deshabilitada}
      aria-label={direccion === 'anterior' ? 'Videos anteriores' : 'Más videos'}
      className="grid size-10 place-items-center rounded-full bg-asfalto-900 text-linea transition hover:bg-asfalto-700 disabled:bg-stone-200 disabled:text-stone-400"
    >
      <svg viewBox="0 0 24 24" className={`size-5 ${direccion === 'anterior' ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="m9 6 6 6-6 6" />
      </svg>
    </button>
  )
}

export function Videos({ videos }: { videos: Video[] }) {
  const carrusel = useCarrusel()

  if (!videos.length) return null

  return (
    <Seccion
      id="videos"
      titulo="Los videos más vistos"
      acciones={
        carrusel.paginas > 1 && (
          <div className="flex shrink-0 gap-2">
            <Flecha direccion="anterior" deshabilitada={carrusel.pagina === 0} onClick={() => carrusel.ir(carrusel.pagina - 1)} />
            <Flecha direccion="siguiente" deshabilitada={carrusel.pagina >= carrusel.paginas - 1} onClick={() => carrusel.ir(carrusel.pagina + 1)} />
          </div>
        )
      }
    >
      <ul
        ref={carrusel.lista}
        onScroll={carrusel.medir}
        className="flex snap-x snap-mandatory gap-3 overflow-x-auto pb-1 sm:gap-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        {videos.map((v, i) => (
          <li
            key={v.id}
            className="flex shrink-0 snap-start flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-stone-200 basis-[calc((100%_-_0.75rem)/2)] sm:basis-[calc((100%_-_2rem)/3)] lg:basis-[calc((100%_-_3rem)/4)]"
          >
            <Reproductor video={v} etiqueta={`#${i + 1}`} />
            <div className="flex flex-1 flex-col p-3">
              <h3 className="line-clamp-2 text-sm font-semibold leading-snug">{v.titulo}</h3>
              <a
                href={v.url}
                target="_blank"
                rel="noopener"
                className="mt-auto pt-2 text-xs text-stone-500 underline decoration-linea decoration-2 underline-offset-2 hover:text-stone-800"
              >
                Ver en Facebook
              </a>
            </div>
          </li>
        ))}
      </ul>

      {carrusel.paginas > 1 && (
        <div className="mt-4 flex justify-center gap-2">
          {Array.from({ length: carrusel.paginas }, (_, i) => (
            <button
              key={i}
              type="button"
              onClick={() => carrusel.ir(i)}
              aria-label={`Grupo ${i + 1} de ${carrusel.paginas}`}
              aria-current={i === carrusel.pagina}
              className={`h-2 rounded-full transition-all ${i === carrusel.pagina ? 'w-6 bg-linea-oscura' : 'w-2 bg-stone-300 hover:bg-stone-400'}`}
            />
          ))}
        </div>
      )}
    </Seccion>
  )
}

const ESTATUS: Record<EstatusDinamica, { texto: string; clase: string }> = {
  activa: { texto: 'En curso', clase: 'bg-linea text-asfalto-900' },
  proxima: { texto: 'Próximamente', clase: 'bg-sky-100 text-sky-800' },
  terminada: { texto: 'Terminada', clase: 'bg-stone-200 text-stone-600' },
}

const ETAPAS = [
  { paso: 1, titulo: 'Lanzamiento', campo: 'video_inicio' },
  { paso: 2, titulo: 'En vivo', campo: 'video_en_vivo' },
  { paso: 3, titulo: 'Ganadores', campo: 'video_cierre' },
] as const

export function Dinamicas({ dinamicas, onVerGanadores }: { dinamicas: Dinamica[]; onVerGanadores: (id: number) => void }) {
  if (!dinamicas.length) return null

  return (
    <Seccion id="dinamicas" titulo="Dinámicas">
      <ul className="grid gap-4 md:grid-cols-2">
        {dinamicas.map((d) => {
          const etapas = ETAPAS.map((e) => ({ ...e, video: d[e.campo] }))
          const conVideo = etapas.filter((e) => e.video)
          // En curso, las etapas que faltan se muestran como "Próximamente"; terminada, solo las que hay.
          const visibles = conVideo.length === 0 ? [] : d.estatus === 'terminada' ? conVideo : etapas

          return (
            <li
              key={d.id}
              className={`flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ${d.estatus === 'activa' ? 'ring-2 ring-linea' : 'ring-stone-200'}`}
            >
              {/* Con videos, la imagen sobra: ellos cuentan la dinámica. */}
              {d.imagen && !visibles.length && <img src={d.imagen} alt="" loading="lazy" className="aspect-[2/1] w-full object-cover" />}
              {visibles.length > 0 && (
                <ol
                  aria-label="La dinámica en video"
                  className={`grid gap-px bg-stone-200 ${['', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3'][visibles.length]}`}
                >
                  {visibles.map((e) => (
                    <li key={e.campo} className="relative">
                      {e.video ? (
                        <Reproductor video={e.video} etiqueta={`${e.paso} · ${e.titulo}`} />
                      ) : (
                        <div className="grid aspect-[3/4] place-items-center bg-stone-100 p-2 text-center">
                          <div>
                            <span className="mx-auto grid size-9 place-items-center rounded-full border-2 border-dashed border-stone-300 text-sm font-bold text-stone-400">
                              {e.paso}
                            </span>
                            <p className="mt-2 text-xs font-semibold text-stone-500">{e.titulo}</p>
                            <p className="text-xs text-stone-400">Próximamente</p>
                          </div>
                        </div>
                      )}
                    </li>
                  ))}
                </ol>
              )}
              <div className="flex flex-1 flex-col p-4">
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  <span className={`rounded-full px-2.5 py-0.5 font-semibold ${ESTATUS[d.estatus].clase}`}>{ESTATUS[d.estatus].texto}</span>
                  {(d.inicia_el || d.termina_el) && (
                    <span className="text-stone-500">
                      {d.inicia_el && fecha(d.inicia_el)}
                      {d.inicia_el && d.termina_el && ' – '}
                      {d.termina_el && fecha(d.termina_el, true)}
                    </span>
                  )}
                </div>
                <h3 className="mt-2 text-lg font-bold leading-snug">{d.titulo}</h3>
                {d.premio && <p className="mt-1 text-sm font-medium text-linea-oscura">Premio: {d.premio}</p>}
                {d.descripcion && <p className="mt-2 line-clamp-6 whitespace-pre-line text-sm text-stone-600">{d.descripcion}</p>}
                {d.ganadores.length > 0 && (
                  <button
                    type="button"
                    onClick={() => onVerGanadores(d.id)}
                    className="group mt-4 flex items-center gap-3 self-start rounded-full bg-stone-100 py-1.5 pl-1.5 pr-4 text-sm font-semibold hover:bg-linea/30"
                  >
                    <span className="flex -space-x-2">
                      {d.ganadores.slice(0, 3).map((g) => (
                        <span key={g.id} className="rounded-full ring-2 ring-white">
                          <Avatar ganador={g} className="size-8 text-xs" />
                        </span>
                      ))}
                    </span>
                    {d.ganadores.length === 1 ? 'Ver al ganador' : `Ver a los ${d.ganadores.length} ganadores`}
                    <span aria-hidden className="transition group-hover:translate-x-0.5">→</span>
                  </button>
                )}
                {d.url_publicacion && d.estatus !== 'terminada' && (
                  <a
                    href={d.url_publicacion}
                    target="_blank"
                    rel="noopener"
                    className="mt-4 self-start rounded-full bg-asfalto-900 px-4 py-2 text-sm font-semibold text-white hover:bg-asfalto-700"
                  >
                    Participa en Facebook
                  </a>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </Seccion>
  )
}

export function Juego() {
  return (
    <Seccion id="reto" titulo="El reto del asfalto" oscura>
      <div className="grid gap-6 sm:grid-cols-[1fr_auto] sm:items-center">
        <div>
          <p className="max-w-xl text-stone-300">
            Muy pronto: pon a prueba lo que sabes del reglamento con preguntas tipo examen de manejo, suma puntos, sube en el
            ranking y gana boletos para las dinámicas.
          </p>
          <p className="mt-3 text-sm text-stone-400">Mientras tanto, pregúntale al asistente vial lo que quieras del reglamento.</p>
        </div>
        <a
          href={URL_CHAT}
          className="justify-self-start rounded-full bg-linea px-6 py-3 font-bold text-asfalto-900 hover:bg-yellow-300"
        >
          Preguntar al asistente
        </a>
      </div>
    </Seccion>
  )
}

export function Avatar({ ganador, className = 'size-12' }: { ganador: Ganador; className?: string }) {
  return ganador.foto ? (
    <img src={ganador.foto} alt="" loading="lazy" className={`${className} shrink-0 rounded-full object-cover`} />
  ) : (
    <span className={`${className} grid shrink-0 place-items-center rounded-full bg-linea font-bold text-asfalto-900`}>{iniciales(ganador.nombre)}</span>
  )
}

const GANADORES_POR_VISTA = 9

export function Ganadores({
  ganadores,
  dinamicas,
  onVerDinamica,
  onVerFoto,
}: {
  ganadores: Ganador[]
  dinamicas: Dinamica[]
  onVerDinamica: (id: number) => void
  onVerFoto: (ganadores: Ganador[], indice: number) => void
}) {
  const [mostrados, setMostrados] = useState(GANADORES_POR_VISTA)

  if (!ganadores.length) return null

  // Solo las dinámicas que el sitio trae completas se pueden abrir.
  const conDetalle = new Set(dinamicas.map((d) => d.id))

  return (
    <Seccion id="ganadores" titulo="Ganadores">
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {ganadores.slice(0, mostrados).map((g, i) => (
          <li key={g.id} className="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-stone-200">
            {g.foto ? (
              <button
                type="button"
                onClick={() => onVerFoto(ganadores, i)}
                aria-label={`Ver foto de ${g.nombre}`}
                className="shrink-0 rounded-full ring-2 ring-transparent transition hover:ring-linea"
              >
                <Avatar ganador={g} className="size-16" />
              </button>
            ) : (
              <Avatar ganador={g} className="size-16" />
            )}
            <div className="min-w-0">
              <p className="truncate font-semibold">{g.nombre}</p>
              {g.premio && <p className="text-sm leading-snug text-linea-oscura">{g.premio}</p>}
              <p className="text-xs leading-snug text-stone-500">
                {g.dinamica && g.dinamica_id && conDetalle.has(g.dinamica_id) ? (
                  <button
                    type="button"
                    onClick={() => onVerDinamica(g.dinamica_id!)}
                    className="text-left underline decoration-linea decoration-2 underline-offset-2 hover:text-stone-800"
                  >
                    {g.dinamica}
                  </button>
                ) : (
                  g.dinamica
                )}
                {g.dinamica && g.fecha && ' · '}
                {g.fecha && fecha(g.fecha, true)}
              </p>
            </div>
          </li>
        ))}
      </ul>

      {mostrados < ganadores.length && (
        <div className="mt-6 flex justify-center">
          <button
            type="button"
            onClick={() => setMostrados((n) => n + GANADORES_POR_VISTA)}
            className="rounded-full bg-asfalto-900 px-5 py-2 text-sm font-semibold text-white hover:bg-asfalto-700"
          >
            Ver más ganadores ({ganadores.length - mostrados})
          </button>
        </div>
      )}
    </Seccion>
  )
}

export function Colaboraciones({ colaboraciones }: { colaboraciones: Colaboracion[] }) {
  if (!colaboraciones.length) return null

  return (
    <Seccion id="colaboraciones" titulo="Han rodado con nosotros">
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {colaboraciones.map((c) => {
          const contenido = (
            <>
              {c.logo ? (
                <img src={c.logo} alt={c.nombre} loading="lazy" className="h-14 w-full object-contain" />
              ) : (
                <span className="grid h-14 place-items-center text-center font-bold">{c.nombre}</span>
              )}
              {c.descripcion && <p className="mt-2 text-xs text-stone-500 line-clamp-3">{c.descripcion}</p>}
            </>
          )
          const clase = 'block h-full rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-200'

          return (
            <li key={c.id} title={c.nombre}>
              {c.url ? (
                <a href={c.url} target="_blank" rel="noopener" className={`${clase} hover:ring-linea`}>{contenido}</a>
              ) : (
                <div className={clase}>{contenido}</div>
              )}
            </li>
          )
        })}
      </ul>
    </Seccion>
  )
}
