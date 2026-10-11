import { useState } from 'react'
import Markdown from 'react-markdown'
import { valorar, type RespuestaConsulta } from '../api'
import { Fuentes } from './Fuentes'

export type Mensaje =
  | { id: string; rol: 'usuario'; texto: string }
  | { id: string; rol: 'asistente'; estado: 'cargando' }
  | { id: string; rol: 'asistente'; estado: 'listo'; resultado: RespuestaConsulta }
  | { id: string; rol: 'asistente'; estado: 'error'; error: string; pregunta: string }

interface Props {
  mensaje: Mensaje
  onReintentar: (pregunta: string) => void
  /** Cambia a ese municipio y repite la pregunta. */
  onPreguntarEnMunicipio: (municipioId: number, pregunta: string) => void
}

export function VistaMensaje({ mensaje, onReintentar, onPreguntarEnMunicipio }: Props) {
  if (mensaje.rol === 'usuario') {
    return (
      <div className="flex justify-end">
        <p className="max-w-[85%] whitespace-pre-line rounded-2xl rounded-br-md bg-marca-700 px-4 py-2.5 text-white shadow-sm senal:rounded-br-sm senal:bg-senal-oscuro senal:font-medium senal:shadow-none">
          {mensaje.texto}
        </p>
      </div>
    )
  }

  return (
    <div className="flex gap-3">
      <Avatar />
      <div className="min-w-0 flex-1 pt-1">
        {mensaje.estado === 'cargando' && <Cargando />}
        {mensaje.estado === 'error' && (
          <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p>{mensaje.error}</p>
            <button
              type="button"
              onClick={() => onReintentar(mensaje.pregunta)}
              className="mt-2 font-medium underline underline-offset-2 hover:text-red-900"
            >
              Reintentar
            </button>
          </div>
        )}
        {mensaje.estado === 'listo' && (
          <Resultado resultado={mensaje.resultado} onPreguntarEnMunicipio={onPreguntarEnMunicipio} />
        )}
      </div>
    </div>
  )
}

function Resultado({
  resultado,
  onPreguntarEnMunicipio,
}: {
  resultado: RespuestaConsulta
  onPreguntarEnMunicipio: Props['onPreguntarEnMunicipio']
}) {
  const [verFundamento, setVerFundamento] = useState(false)
  const sinFuentes = resultado.fuentes.length === 0
  const sugerencia = resultado.sugerir_municipios.length > 0 && (
    <SugerenciaMunicipios
      municipios={resultado.sugerir_municipios}
      onElegir={(id) => onPreguntarEnMunicipio(id, resultado.pregunta)}
    />
  )

  // Sin respuesta redactada (LLM no disponible), los artículos SON la respuesta:
  // se muestran siempre, con el más relevante abierto.
  if (!resultado.respuesta) {
    return (
      <div className="space-y-4">
        <p className="leading-relaxed text-stone-800">
          {sinFuentes
            ? `No encontré artículos del reglamento de ${resultado.ubicacion} relacionados con tu pregunta. Prueba a formularla con otras palabras.`
            : (resultado.aviso ?? 'Estos son los artículos del reglamento más relacionados con tu pregunta:')}
        </p>
        {sugerencia}
        {!sinFuentes && <Fuentes fuentes={resultado.fuentes} abrirPrimera />}
      </div>
    )
  }

  const articulos = contarArticulos(resultado.fuentes)

  return (
    <div className="space-y-4">
      <div className="respuesta leading-relaxed text-stone-800 senal:text-[15.5px] senal:text-senal-oscuro">
        <Markdown>{resultado.respuesta}</Markdown>
      </div>

      {sugerencia}

      <Valoracion consultaId={resultado.consulta_id} />

      {!sinFuentes && (
        <section>
          <button
            type="button"
            onClick={() => setVerFundamento(!verFundamento)}
            aria-expanded={verFundamento}
            className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 -ml-2 text-sm font-medium text-marca-700 transition hover:bg-marca-50 senal:ml-0 senal:rounded-full senal:border-2 senal:border-senal-oscuro senal:px-3.5 senal:py-1.5 senal:font-bold senal:text-senal-oscuro senal:hover:bg-senal-acento"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="size-4">
              <path fillRule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-4.12-4.122A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clipRule="evenodd" />
            </svg>
            {verFundamento ? 'Ocultar fundamento' : `Ver fundamento (${articulos} ${articulos === 1 ? 'artículo' : 'artículos'})`}
          </button>
          {verFundamento && (
            <div className="mt-2">
              <Fuentes fuentes={resultado.fuentes} />
            </div>
          )}
        </section>
      )}
    </div>
  )
}

function Valoracion({ consultaId }: { consultaId: string }) {
  const [elegida, setElegida] = useState<boolean | null>(null)

  function enviar(util: boolean) {
    setElegida(util)
    // Si falla no se interrumpe al usuario: la valoración es opcional.
    valorar(consultaId, util).catch(() => undefined)
  }

  if (elegida !== null) {
    return <p className="text-sm text-stone-500">¡Gracias! Tu opinión nos ayuda a mejorar las respuestas.</p>
  }

  const boton =
    'inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 py-1 text-sm text-stone-600 shadow-sm transition hover:bg-stone-50 senal:rounded-full senal:border-senal-borde senal:font-semibold senal:text-senal-oscuro senal:shadow-none senal:hover:border-senal-oscuro'

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm text-stone-500">
      <span>¿Te sirvió esta respuesta?</span>
      <button type="button" onClick={() => enviar(true)} className={boton} aria-label="Sí me sirvió">
        <Pulgar /> <span>Sí</span>
      </button>
      <button type="button" onClick={() => enviar(false)} className={boton} aria-label="No me sirvió">
        <Pulgar abajo /> <span>No</span>
      </button>
    </div>
  )
}

function Pulgar({ abajo = false }: { abajo?: boolean }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={`size-4 ${abajo ? 'rotate-180' : ''}`}
    >
      <path d="M7 10v11H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h3Zm0 0 4-7a2.5 2.5 0 0 1 2.5 2.5V9h5.2a2 2 0 0 1 2 2.3l-1.2 7.5a2 2 0 0 1-2 1.7H7" />
    </svg>
  )
}

function SugerenciaMunicipios({
  municipios,
  onElegir,
}: {
  municipios: RespuestaConsulta['sugerir_municipios']
  onElegir: (municipioId: number) => void
}) {
  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 senal:border-l-4 senal:border-senal-acento senal:bg-white senal:text-stone-700">
      <p>
        Muchas reglas de circulación (semáforos, vueltas, estacionamiento) están en los reglamentos municipales.
        {municipios.length === 1 ? ' ¿Circulas en este municipio?' : ' ¿Circulas en alguno de estos municipios?'}
      </p>
      <div className="mt-2 flex flex-wrap gap-2">
        {municipios.map((m) => (
          <button
            key={m.id}
            type="button"
            onClick={() => onElegir(m.id)}
            className="rounded-lg bg-white px-3 py-1.5 font-medium text-amber-900 shadow-sm ring-1 ring-amber-200 transition hover:bg-amber-100 senal:rounded-full senal:bg-senal-oscuro senal:font-bold senal:text-white senal:ring-0 senal:hover:bg-senal-oscuro/85"
          >
            Preguntar en {m.nombre}
          </button>
        ))}
      </div>
    </div>
  )
}

/** Artículos distintos (varios apartados del mismo artículo cuentan como uno). */
function contarArticulos(fuentes: RespuestaConsulta['fuentes']): number {
  return new Set(fuentes.map((f, i) => (f.articulo ? `${f.documento_id}-${f.articulo}` : `sin-${i}`))).size
}

function Avatar() {
  return (
    <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-marca-700 text-white shadow-sm senal:bg-senal-acento senal:text-senal-oscuro senal:shadow-none" aria-hidden="true">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="size-4">
        <path d="M7 15v-4a2 2 0 0 1 2-2h5M12 6.5 14.5 9 12 11.5" />
      </svg>
    </div>
  )
}

function Cargando() {
  return (
    <div className="space-y-2.5" role="status">
      <p className="text-sm text-stone-500 senal:font-titulos senal:text-base senal:font-bold senal:uppercase senal:tracking-wider senal:text-senal-oscuro">Buscando en el reglamento…</p>
      <div aria-hidden="true" className="carril-buscando hidden w-40 rounded-full senal:block" />
      <div className="h-3 w-11/12 animate-pulse rounded bg-stone-200" />
      <div className="h-3 w-4/5 animate-pulse rounded bg-stone-200" />
      <div className="h-3 w-2/3 animate-pulse rounded bg-stone-200" />
    </div>
  )
}
