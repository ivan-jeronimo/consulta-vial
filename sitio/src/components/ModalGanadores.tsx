import { useEffect, useRef } from 'react'
import type { Dinamica, Ganador } from '../api'
import { fecha } from '../formato'
import { Avatar } from './Secciones'

/**
 * Ganadores de una dinámica. <dialog> nativo: Esc y el botón "atrás" del
 * teclado lo cierran, y el foco queda atrapado dentro. En celular sale como
 * hoja desde abajo; en pantallas grandes, centrado.
 */
export function ModalGanadores({
  dinamica,
  onCerrar,
  onVerFoto,
}: {
  dinamica: Dinamica | null
  onCerrar: () => void
  onVerFoto: (ganadores: Ganador[], indice: number) => void
}) {
  const dialogo = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const el = dialogo.current
    if (!el) return
    if (dinamica && !el.open) el.showModal()
    if (!dinamica && el.open) el.close()
  }, [dinamica])

  return (
    <dialog
      ref={dialogo}
      onClose={onCerrar}
      // Un clic en el fondo oscuro (fuera del contenido) cierra.
      onClick={(e) => e.target === dialogo.current && dialogo.current.close()}
      aria-labelledby="titulo-ganadores"
      className="hoja m-0 mt-auto max-h-[88dvh] w-full max-w-none overflow-hidden rounded-t-3xl bg-white p-0 text-stone-900 shadow-2xl backdrop:bg-asfalto-950/70 backdrop:backdrop-blur-sm sm:m-auto sm:max-w-lg sm:rounded-3xl"
    >
      {dinamica && (
        <div className="flex max-h-[88dvh] flex-col">
          <header className="relative bg-asfalto-900 px-5 pb-5 pt-6 text-white">
            <div className="mx-auto mb-3 h-1 w-10 rounded-full bg-white/30 sm:hidden" aria-hidden />
            <button
              type="button"
              onClick={() => dialogo.current?.close()}
              aria-label="Cerrar"
              className="absolute right-3 top-3 grid size-9 place-items-center rounded-full text-stone-300 hover:bg-white/10 hover:text-white"
            >
              <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" aria-hidden>
                <path d="M6 6l12 12M18 6 6 18" />
              </svg>
            </button>
            <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-linea">
              <TrofeoIcono /> {dinamica.ganadores.length === 1 ? 'Ganador' : 'Ganadores'}
            </p>
            <h2 id="titulo-ganadores" className="mt-1 pr-8 text-xl font-black leading-tight">{dinamica.titulo}</h2>
            {(dinamica.inicia_el || dinamica.termina_el) && (
              <p className="mt-1 text-sm text-stone-400">
                {dinamica.inicia_el && fecha(dinamica.inicia_el)}
                {dinamica.inicia_el && dinamica.termina_el && ' – '}
                {dinamica.termina_el && fecha(dinamica.termina_el, true)}
              </p>
            )}
          </header>
          <div className="linea-carretera shrink-0" />

          <div className="overflow-y-auto px-5 py-5">
            <ul className={`grid gap-3 ${dinamica.ganadores.length > 1 ? 'grid-cols-2' : 'mx-auto max-w-64 grid-cols-1'}`}>
              {dinamica.ganadores.map((g, i) => (
                <li key={g.id} className="overflow-hidden rounded-2xl bg-stone-50 ring-1 ring-stone-200">
                  {g.foto ? (
                    <button
                      type="button"
                      onClick={() => onVerFoto(dinamica.ganadores, i)}
                      className="group relative block aspect-[4/5] w-full overflow-hidden bg-stone-200"
                      aria-label={`Ver foto de ${g.nombre}`}
                    >
                      <img src={g.foto} alt="" loading="lazy" className="size-full object-cover transition duration-300 group-hover:scale-105" />
                      <span className="absolute bottom-2 right-2 grid size-8 place-items-center rounded-full bg-asfalto-900/80 text-white" aria-hidden>
                        <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round">
                          <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                        </svg>
                      </span>
                    </button>
                  ) : (
                    <div className="grid aspect-[4/5] place-items-center bg-stone-100">
                      <Avatar ganador={g} className="size-20 text-2xl" />
                    </div>
                  )}
                  <div className="p-3">
                    <p className="font-bold leading-tight">{g.nombre}</p>
                    {g.premio && <p className="mt-0.5 text-sm font-medium leading-snug text-linea-oscura">{g.premio}</p>}
                    {g.fecha && <p className="mt-0.5 text-xs text-stone-500">{fecha(g.fecha, true)}</p>}
                  </div>
                </li>
              ))}
            </ul>

            {dinamica.url_publicacion && (
              <a
                href={dinamica.url_publicacion}
                target="_blank"
                rel="noopener"
                className="mt-6 inline-block text-sm text-stone-600 underline decoration-linea decoration-2 underline-offset-2 hover:text-stone-900"
              >
                Ver la dinámica en Facebook
              </a>
            )}
          </div>
        </div>
      )}
    </dialog>
  )
}

function TrofeoIcono() {
  return (
    <svg viewBox="0 0 24 24" className="size-4" fill="currentColor" aria-hidden>
      <path d="M7 3h10v2h3v3a4 4 0 0 1-4 4h-.35A5 5 0 0 1 13 14.9V17h3v4H8v-4h3v-2.1A5 5 0 0 1 8.35 12H8a4 4 0 0 1-4-4V5h3zm0 4H6v1a2 2 0 0 0 1 1.73zm10 0v2.73A2 2 0 0 0 18 8V7z" />
    </svg>
  )
}
