import { useEffect, useRef, useState, type ReactNode } from 'react'
import type { Ganador } from '../api'
import { fecha } from '../formato'

export interface EstadoVisor {
  ganadores: Ganador[]
  indice: number
}

/**
 * Foto de un ganador en un modal (encima del de la dinámica). Si hay varios se
 * recorren con las flechas o ← →. "Expandir" la lleva a pantalla completa; Esc
 * primero la regresa al modal y después lo cierra.
 */
export function VisorFoto({ estado, onCambiar, onCerrar }: { estado: EstadoVisor | null; onCambiar: (indice: number) => void; onCerrar: () => void }) {
  const dialogo = useRef<HTMLDialogElement>(null)
  const [expandido, setExpandido] = useState(false)

  useEffect(() => {
    const el = dialogo.current
    if (!el) return
    if (estado && !el.open) el.showModal()
    if (!estado && el.open) el.close()
  }, [estado])

  // Solo se recorren los que tienen foto.
  const conFoto = estado?.ganadores.filter((g) => g.foto) ?? []
  const actual = estado ? estado.ganadores[estado.indice] : null
  const posicion = actual ? conFoto.indexOf(actual) : -1
  const ir = (paso: number) => {
    const siguiente = conFoto[(posicion + paso + conFoto.length) % conFoto.length]
    if (estado && siguiente) onCambiar(estado.ganadores.indexOf(siguiente))
  }

  return (
    <dialog
      ref={dialogo}
      onClose={() => {
        setExpandido(false)
        onCerrar()
      }}
      onCancel={(e) => {
        if (expandido) {
          e.preventDefault()
          setExpandido(false)
        }
      }}
      onClick={(e) => e.target === e.currentTarget && dialogo.current?.close()}
      onKeyDown={(e) => {
        if (conFoto.length < 2) return
        if (e.key === 'ArrowRight') ir(1)
        if (e.key === 'ArrowLeft') ir(-1)
      }}
      aria-label={actual ? `Foto de ${actual.nombre}` : 'Foto'}
      className={
        expandido
          ? 'm-0 h-dvh max-h-none w-screen max-w-none bg-asfalto-950 p-0 text-white backdrop:bg-asfalto-950'
          : 'hoja m-auto max-h-[92dvh] w-[min(92vw,34rem)] max-w-none overflow-hidden rounded-3xl bg-white p-0 text-stone-900 shadow-2xl backdrop:bg-asfalto-950/80 backdrop:backdrop-blur-sm'
      }
    >
      {actual?.foto && (
        <div className={`flex flex-col ${expandido ? 'h-full' : 'max-h-[92dvh]'}`}>
          <div className={`relative min-h-0 bg-asfalto-950 ${expandido ? 'flex-1' : 'grid place-items-center'}`}>
            <img
              src={actual.foto}
              alt={`${actual.nombre}${actual.premio ? ` con su premio: ${actual.premio}` : ''}`}
              className={expandido ? 'absolute inset-0 size-full object-contain' : 'max-h-[68dvh] w-full object-contain'}
            />

            <div className="absolute right-3 top-3 flex gap-2">
              <BotonRedondo etiqueta={expandido ? 'Salir de pantalla completa' : 'Ver en pantalla completa'} onClick={() => setExpandido((v) => !v)}>
                {expandido ? <path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7" /> : <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />}
              </BotonRedondo>
              <BotonRedondo etiqueta="Cerrar" onClick={() => dialogo.current?.close()}>
                <path d="M6 6l12 12M18 6 6 18" />
              </BotonRedondo>
            </div>

            {conFoto.length > 1 &&
              ([-1, 1] as const).map((paso) => (
                <div key={paso} className={`absolute top-1/2 -translate-y-1/2 ${paso < 0 ? 'left-3' : 'right-3'}`}>
                  <BotonRedondo etiqueta={paso < 0 ? 'Ganador anterior' : 'Siguiente ganador'} onClick={() => ir(paso)}>
                    <path d="m9 6 6 6-6 6" transform={paso < 0 ? 'rotate(180 12 12)' : undefined} />
                  </BotonRedondo>
                </div>
              ))}
          </div>

          <div className={`flex items-start justify-between gap-4 px-5 py-4 ${expandido ? 'bg-asfalto-950 text-center sm:justify-center' : ''}`}>
            <div className={expandido ? 'w-full' : 'min-w-0'}>
              <p className="text-lg font-bold leading-tight">{actual.nombre}</p>
              {actual.premio && <p className={`mt-0.5 text-sm font-medium ${expandido ? 'text-linea' : 'text-linea-oscura'}`}>{actual.premio}</p>}
              {actual.dinamica && (
                <p className={`mt-0.5 text-xs ${expandido ? 'text-stone-400' : 'text-stone-500'}`}>
                  {actual.dinamica}
                  {actual.fecha && ` · ${fecha(actual.fecha, true)}`}
                </p>
              )}
            </div>
            {conFoto.length > 1 && !expandido && <p className="shrink-0 pt-1 text-xs text-stone-400">{posicion + 1} de {conFoto.length}</p>}
          </div>
        </div>
      )}
    </dialog>
  )
}

function BotonRedondo({ etiqueta, onClick, children }: { etiqueta: string; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={etiqueta}
      title={etiqueta}
      className="grid size-10 place-items-center rounded-full bg-asfalto-900/70 text-white backdrop-blur transition hover:bg-asfalto-900 hover:text-linea"
    >
      <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        {children}
      </svg>
    </button>
  )
}
