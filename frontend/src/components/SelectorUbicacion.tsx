import { useState } from 'react'
import type { Cobertura, Estado, Ubicacion } from '../api'
import { PlacaNivel } from './Fuentes'

interface Props {
  cobertura: Cobertura
  inicial: Ubicacion | null
  onElegir: (ubicacion: Ubicacion) => void
  onCancelar?: () => void
}

/** Todo el estado (solo el reglamento estatal) o un municipio (el suyo y el estatal). */
type Alcance = 'estado' | 'municipio'

export function SelectorUbicacion({ cobertura, inicial, onElegir, onCancelar }: Props) {
  const [estadoId, setEstadoId] = useState<number | null>(
    inicial?.estadoId ?? (cobertura.estados.length === 1 ? cobertura.estados[0].id : null),
  )
  const estado = cobertura.estados.find((e) => e.id === estadoId) ?? null

  const [alcance, setAlcance] = useState<Alcance>(
    inicial ? (inicial.municipioId === null ? 'estado' : 'municipio') : alcanceInicial(estado),
  )
  const [municipioId, setMunicipioId] = useState<number | null>(inicial?.municipioId ?? estado?.municipios[0]?.id ?? null)

  function cambiarEstado(id: number) {
    const nuevo = cobertura.estados.find((e) => e.id === id) ?? null
    setEstadoId(id)
    setMunicipioId(nuevo?.municipios[0]?.id ?? null)
    setAlcance(alcanceInicial(nuevo))
  }

  const municipio = estado?.municipios.find((m) => m.id === municipioId) ?? null
  const puedeContinuar = estado !== null && (alcance === 'estado' ? estado.tiene_reglamento_estatal : municipio !== null)
  // Solo se pregunta "todo el estado o un municipio" cuando hay de dónde elegir.
  const hayEleccion = estado !== null && estado.tiene_reglamento_estatal && estado.municipios.length > 0

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
        className="mt-6 space-y-5"
        onSubmit={(e) => {
          e.preventDefault()
          if (estado && puedeContinuar) {
            onElegir({ estadoId: estado.id, municipioId: alcance === 'estado' ? null : municipio!.id })
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

        {estado && hayEleccion && (
          <fieldset>
            <legend className="mb-1.5 text-sm font-medium text-stone-700">¿Qué quieres consultar?</legend>
            <div className="space-y-2">
              <Opcion
                elegida={alcance === 'estado'}
                onElegir={() => setAlcance('estado')}
                titulo={`Todo el estado de ${estado.nombre}`}
                detalle="Solo el reglamento estatal. Para circular en cualquier parte del estado."
              />
              <Opcion
                elegida={alcance === 'municipio'}
                onElegir={() => setAlcance('municipio')}
                titulo="Un municipio"
                detalle="El reglamento de tu municipio y también el estatal, que aplica ahí mismo."
              >
                {alcance === 'municipio' && <SelectMunicipio estado={estado} valor={municipioId} onCambiar={setMunicipioId} />}
              </Opcion>
            </div>
          </fieldset>
        )}

        {/* Sin reglamento estatal: el municipio es la única opción. */}
        {estado && !estado.tiene_reglamento_estatal && estado.municipios.length > 0 && (
          <div>
            <span className="mb-1.5 block text-sm font-medium text-stone-700">Municipio</span>
            <SelectMunicipio estado={estado} valor={municipioId} onCambiar={setMunicipioId} />
          </div>
        )}

        {estado && puedeContinuar && (
          <div className="rounded-xl bg-stone-50 px-4 py-3 senal:rounded-lg senal:bg-senal-fondo">
            <p className="text-xs font-medium text-stone-500 senal:font-bold senal:uppercase senal:tracking-wider">Se consultará</p>
            <p className="mt-1.5 flex flex-wrap gap-1.5">
              {alcance === 'municipio' && municipio && <PlacaNivel nivel="municipal" lugar={municipio.nombre} />}
              {estado.tiene_reglamento_estatal && <PlacaNivel nivel="estatal" lugar={estado.nombre} />}
              {cobertura.hay_normativa_federal && <PlacaNivel nivel="federal" lugar="México" />}
            </p>
          </div>
        )}

        <div className="flex gap-3 pt-1">
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

/** Con municipios disponibles se propone el primero; si no, todo el estado. */
function alcanceInicial(estado: Estado | null): Alcance {
  return estado && estado.municipios.length > 0 ? 'municipio' : 'estado'
}

function Opcion({
  elegida,
  onElegir,
  titulo,
  detalle,
  children,
}: {
  elegida: boolean
  onElegir: () => void
  titulo: string
  detalle: string
  children?: React.ReactNode
}) {
  return (
    <div
      className={`rounded-xl border px-4 py-3 transition ${
        elegida
          ? 'border-marca-600 bg-marca-50/60 ring-1 ring-marca-600 senal:border-2 senal:border-senal-oscuro senal:bg-white senal:ring-0'
          : 'border-stone-200 hover:border-stone-300 senal:border-2 senal:border-senal-borde senal:hover:border-senal-oscuro/50'
      }`}
    >
      <label className="flex cursor-pointer items-start gap-3">
        <input
          type="radio"
          name="alcance"
          checked={elegida}
          onChange={onElegir}
          className="mt-1 size-4 shrink-0 accent-marca-700 senal:accent-senal-oscuro"
        />
        <span>
          <span className="block font-medium text-stone-900 senal:font-bold senal:text-senal-oscuro">{titulo}</span>
          <span className="block text-sm text-stone-600">{detalle}</span>
        </span>
      </label>
      {children && <div className="mt-3 pl-7">{children}</div>}
    </div>
  )
}

function SelectMunicipio({ estado, valor, onCambiar }: { estado: Estado; valor: number | null; onCambiar: (id: number) => void }) {
  return (
    <span className="relative block">
      <select
        aria-label="Municipio"
        className={estiloSelect}
        value={valor ?? ''}
        onChange={(e) => onCambiar(Number(e.target.value))}
      >
        {estado.municipios.map((m) => (
          <option key={m.id} value={m.id}>{m.nombre}</option>
        ))}
      </select>
      <Flecha />
    </span>
  )
}

const estiloSelect =
  'w-full appearance-none rounded-xl border border-stone-300 bg-white px-4 py-3 pr-10 text-base shadow-sm focus:border-marca-600 focus:outline-none focus:ring-2 focus:ring-marca-600/20 senal:border-2 senal:border-senal-oscuro/80 senal:font-semibold senal:shadow-none senal:focus:ring-4 senal:focus:ring-senal-acento/50'

function Campo({ etiqueta, children }: { etiqueta: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-stone-700">{etiqueta}</span>
      <span className="relative block">
        {children}
        <Flecha />
      </span>
    </label>
  )
}

function Flecha() {
  return (
    <svg
      viewBox="0 0 20 20"
      fill="currentColor"
      aria-hidden="true"
      className="pointer-events-none absolute top-1/2 right-3 size-5 -translate-y-1/2 text-stone-500"
    >
      <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
    </svg>
  )
}

function Tarjeta({ children }: { children: React.ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8 senal:border-senal-borde senal:shadow-none">
      {children}
    </div>
  )
}
