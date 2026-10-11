import { useState } from 'react'
import type { Cobertura, Ubicacion } from '../api'

interface Props {
  cobertura: Cobertura
  inicial: Ubicacion | null
  onElegir: (ubicacion: Ubicacion) => void
  onCancelar?: () => void
}

const SOLO_ESTATAL = ''

export function SelectorUbicacion({ cobertura, inicial, onElegir, onCancelar }: Props) {
  const [estadoId, setEstadoId] = useState<number | null>(
    inicial?.estadoId ?? (cobertura.estados.length === 1 ? cobertura.estados[0].id : null),
  )
  const estado = cobertura.estados.find((e) => e.id === estadoId) ?? null

  const [municipio, setMunicipio] = useState<string>(
    inicial?.municipioId != null ? String(inicial.municipioId) : primeraOpcion(estado),
  )

  function cambiarEstado(id: number) {
    setEstadoId(id)
    setMunicipio(primeraOpcion(cobertura.estados.find((e) => e.id === id) ?? null))
  }

  const puedeContinuar = estado !== null && (municipio !== SOLO_ESTATAL || estado.tiene_reglamento_estatal)

  if (cobertura.estados.length === 0) {
    return (
      <Tarjeta>
        <p className="text-stone-600">
          Todavía no hay reglamentos disponibles para consulta. Vuelve pronto.
        </p>
      </Tarjeta>
    )
  }

  return (
    <Tarjeta>
      <h2 className="text-xl font-semibold tracking-tight senal:font-titulos senal:text-3xl senal:font-extrabold senal:uppercase senal:leading-none">¿Dónde vas a circular?</h2>
      <p className="mt-1 text-sm text-stone-600">
        Las reglas cambian según el estado y el municipio. Elige el lugar para consultar su reglamento.
      </p>

      <form
        className="mt-6 space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          if (estado && puedeContinuar) {
            onElegir({ estadoId: estado.id, municipioId: municipio === SOLO_ESTATAL ? null : Number(municipio) })
          }
        }}
      >
        <Campo etiqueta="Estado">
          <select
            className={estiloSelect}
            value={estadoId ?? ''}
            onChange={(e) => cambiarEstado(Number(e.target.value))}
            required
          >
            {estadoId === null && <option value="" disabled>Elige un estado</option>}
            {cobertura.estados.map((e) => (
              <option key={e.id} value={e.id}>{e.nombre}</option>
            ))}
          </select>
        </Campo>

        {estado && (estado.municipios.length > 0 || estado.tiene_reglamento_estatal) && (
          <Campo etiqueta="Municipio">
            <select className={estiloSelect} value={municipio} onChange={(e) => setMunicipio(e.target.value)}>
              {estado.municipios.map((m) => (
                <option key={m.id} value={m.id}>{m.nombre}</option>
              ))}
              {estado.tiene_reglamento_estatal && (
                <option value={SOLO_ESTATAL}>
                  {estado.municipios.length > 0 ? 'Otro municipio (solo reglamento estatal)' : 'Todo el estado'}
                </option>
              )}
            </select>
          </Campo>
        )}

        <div className="flex gap-3 pt-2">
          <button
            type="submit"
            disabled={!puedeContinuar}
            className="flex-1 rounded-xl bg-marca-700 px-4 py-3 font-medium text-white shadow-sm transition hover:bg-marca-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-marca-700 disabled:opacity-50 senal:rounded-full senal:bg-senal-acento senal:font-bold senal:text-senal-oscuro senal:shadow-none senal:hover:bg-senal-acento/85"
          >
            Continuar
          </button>
          {onCancelar && (
            <button
              type="button"
              onClick={onCancelar}
              className="rounded-xl px-4 py-3 font-medium text-stone-700 transition hover:bg-stone-100"
            >
              Cancelar
            </button>
          )}
        </div>
      </form>
    </Tarjeta>
  )
}

function primeraOpcion(estado: { municipios: { id: number }[] } | null): string {
  return estado?.municipios[0] ? String(estado.municipios[0].id) : SOLO_ESTATAL
}

const estiloSelect =
  'w-full appearance-none rounded-xl border border-stone-300 bg-white px-4 py-3 pr-10 text-base shadow-sm focus:border-marca-600 focus:outline-none focus:ring-2 focus:ring-marca-600/20 senal:border-2 senal:border-senal-oscuro/80 senal:font-semibold senal:shadow-none senal:focus:ring-4 senal:focus:ring-senal-acento/50'

function Campo({ etiqueta, children }: { etiqueta: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-stone-700">{etiqueta}</span>
      <span className="relative block">
        {children}
        <svg
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
          className="pointer-events-none absolute top-1/2 right-3 size-5 -translate-y-1/2 text-stone-500"
        >
          <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
        </svg>
      </span>
    </label>
  )
}

function Tarjeta({ children }: { children: React.ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8 senal:border-senal-borde senal:shadow-none">
      {children}
    </div>
  )
}
