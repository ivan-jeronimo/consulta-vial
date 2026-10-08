import { useEffect, useState } from 'react'
import { obtenerContenido, type ContenidoSitio } from './api'
import { ModalGanadores } from './components/ModalGanadores'
import { EnlacesRedes, SeguidoresPorRed } from './components/Redes'
import { Reto } from './components/Reto'
import { Colaboraciones, Dinamicas, Ganadores, Videos } from './components/Secciones'
import { VisorFoto, type EstadoVisor } from './components/VisorFoto'
import { compacto } from './formato'

export default function App() {
  const [contenido, setContenido] = useState<ContenidoSitio | null>(null)
  const [error, setError] = useState(false)
  // Enlace directo a los ganadores de una dinámica (…/#dinamica-3), para compartir en Facebook.
  const [dinamicaAbierta, setDinamicaAbierta] = useState<number | null>(() => {
    const id = /^#dinamica-(\d+)$/.exec(location.hash)?.[1]
    return id ? Number(id) : null
  })

  const [visor, setVisor] = useState<EstadoVisor | null>(null)

  useEffect(() => {
    obtenerContenido().then(setContenido).catch(() => setError(true))
  }, [])

  // Las secciones aparecen después de cargar: el navegador no alcanza a bajar al #ancla
  // (p. ej. al volver del login de Facebook a /#reto). Se hace a mano una vez.
  useEffect(() => {
    const ancla = /^#([a-z]+)$/.exec(location.hash)?.[1]
    if (contenido && ancla) requestAnimationFrame(() => document.getElementById(ancla)?.scrollIntoView())
  }, [contenido])

  useEffect(() => {
    const hash = dinamicaAbierta ? `#dinamica-${dinamicaAbierta}` : ''
    if (location.hash !== hash && (hash || location.hash.startsWith('#dinamica-'))) {
      history.replaceState(null, '', hash || location.pathname + location.search)
    }
  }, [dinamicaAbierta])

  const comunidad = contenido?.comunidad ?? { nombre: 'Andantes del Asfalto', lema: null, url_facebook: null, logo: null, portada: null, redes: [] }

  const navegacion = [
    contenido?.videos.length && ['#videos', 'Videos'],
    contenido?.dinamicas.length && ['#dinamicas', 'Dinámicas'],
    ['#reto', 'El reto'],
    contenido?.ganadores.length && ['#ganadores', 'Ganadores'],
  ].filter(Boolean) as [string, string][]

  return (
    <div className="min-h-dvh flex flex-col">
      <header className="sticky top-0 z-20 bg-asfalto-900/95 backdrop-blur text-white">
        <div className="mx-auto max-w-5xl flex items-center gap-3 px-4 h-14">
          <a href="#inicio" className="flex items-center gap-2 font-bold tracking-tight min-w-0">
            <img src={comunidad.logo ?? '/icono.svg'} alt="" className="size-8 shrink-0 rounded-full" />
            <span className="truncate">{comunidad.nombre}</span>
          </a>
          <nav className="ml-auto hidden sm:flex gap-5 text-sm text-stone-300">
            {navegacion.map(([href, texto]) => (
              <a key={href} href={href} className="hover:text-linea">{texto}</a>
            ))}
          </nav>
          {comunidad.url_facebook && (
            <a
              href={comunidad.url_facebook}
              target="_blank"
              rel="noopener"
              className="ml-auto sm:ml-0 shrink-0 rounded-full bg-linea px-4 py-1.5 text-sm font-semibold text-asfalto-900 hover:bg-yellow-300"
            >
              Síguenos
            </a>
          )}
        </div>
      </header>

      <main id="inicio" className="flex-1">
        <section className="relative isolate overflow-hidden bg-asfalto-900 text-white">
          {comunidad.portada && (
            <>
              <img src={comunidad.portada} alt="" className="absolute inset-0 -z-10 size-full object-cover" />
              <div className="absolute inset-0 -z-10 bg-gradient-to-t from-asfalto-900 via-asfalto-900/80 to-asfalto-900/40" />
            </>
          )}
          <div className="mx-auto max-w-5xl px-4 pt-12 pb-10 sm:pt-20">
            {comunidad.logo && (
              <img src={comunidad.logo} alt="" className="mb-4 size-20 sm:size-24 rounded-full ring-4 ring-linea shadow-xl" />
            )}
            <h1 className="text-4xl sm:text-6xl font-black tracking-tight">{comunidad.nombre}</h1>
            <p className="mt-3 max-w-xl text-lg text-stone-300">
              {comunidad.lema ?? 'La comunidad que comparte el camino: cultura vial, dinámicas y premios.'}
            </p>

            {contenido && (
              <>
                <dl className="mt-8 grid grid-cols-3 gap-3 max-w-lg">
                  <Dato
                    valor={contenido.estadisticas.seguidores}
                    etiqueta={comunidad.redes.filter((r) => r.seguidores != null).length > 1 ? 'seguidores en redes' : 'seguidores'}
                  />
                  <Dato valor={contenido.estadisticas.dinamicas} etiqueta="dinámicas" />
                  <Dato valor={contenido.estadisticas.premios_entregados} etiqueta="premios entregados" />
                </dl>
                <SeguidoresPorRed redes={comunidad.redes} />
              </>
            )}
          </div>
          <div className="linea-carretera opacity-80" />
        </section>

        {error && (
          <p className="mx-auto max-w-5xl px-4 py-8 text-stone-600">
            No pudimos cargar el contenido. Intenta de nuevo en un momento.
          </p>
        )}

        {!contenido && !error && (
          <div className="mx-auto max-w-5xl px-4 py-12 grid gap-4 sm:grid-cols-3" aria-hidden>
            {[0, 1, 2].map((i) => <div key={i} className="h-48 rounded-2xl bg-stone-200 animate-pulse" />)}
          </div>
        )}

        {contenido && (
          <>
            <Videos videos={contenido.videos} />
            <Dinamicas dinamicas={contenido.dinamicas} onVerGanadores={setDinamicaAbierta} />
            <Reto />
            <Colaboraciones colaboraciones={contenido.colaboraciones} />
            {/* Al final, justo antes del pie: la lista crece con cada dinámica. */}
            <Ganadores
              ganadores={contenido.ganadores}
              dinamicas={contenido.dinamicas}
              onVerDinamica={setDinamicaAbierta}
              onVerFoto={(ganadores, indice) => setVisor({ ganadores, indice })}
            />
            <ModalGanadores
              dinamica={contenido.dinamicas.find((d) => d.id === dinamicaAbierta) ?? null}
              onCerrar={() => setDinamicaAbierta(null)}
              onVerFoto={(ganadores, indice) => setVisor({ ganadores, indice })}
            />
            <VisorFoto
              estado={visor}
              onCambiar={(indice) => setVisor((v) => v && { ...v, indice })}
              onCerrar={() => setVisor(null)}
            />
          </>
        )}
      </main>

      <footer className="bg-asfalto-950 text-stone-400 text-sm">
        <div className="mx-auto max-w-5xl px-4 py-8 flex flex-col-reverse sm:flex-row gap-4 sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} {comunidad.nombre}</p>
          <EnlacesRedes redes={comunidad.redes} />
        </div>
      </footer>
    </div>
  )
}

function Dato({ valor, etiqueta }: { valor: number | null; etiqueta: string }) {
  return (
    <div className="rounded-xl bg-asfalto-800 px-3 py-3">
      <dd className="text-2xl sm:text-3xl font-black text-linea tabular-nums">{valor == null ? '—' : compacto(valor)}</dd>
      <dt className="text-xs sm:text-sm text-stone-400 leading-tight">{etiqueta}</dt>
    </div>
  )
}
