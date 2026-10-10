import { useEffect, useRef, useState } from 'react'
import { consultar, ErrorConsulta, obtenerCobertura, type Cobertura, type Ubicacion } from './api'
import { CampoPregunta } from './components/CampoPregunta'
import { VistaMensaje, type Mensaje } from './components/Mensaje'
import { SelectorUbicacion } from './components/SelectorUbicacion'
import { estadoInicial, incrustado, marca } from './incrustado'

const CLAVE_UBICACION = 'consulta-vial:ubicacion'

// crypto.randomUUID() solo existe en contextos seguros (falla al abrir la app
// por la IP de la red local sin HTTPS); un contador basta para las keys.
let siguienteId = 0
const nuevoId = () => String(++siguienteId)

const EJEMPLOS = [
  '¿Puedo dar vuelta a la derecha con el semáforo en rojo?',
  '¿Cuál es la velocidad máxima en zonas escolares?',
  '¿Es obligatorio el casco para motociclistas?',
  '¿Qué pasa si me estaciono en lugar prohibido?',
]

export default function App() {
  const [cobertura, setCobertura] = useState<Cobertura | null>(null)
  const [errorCarga, setErrorCarga] = useState<string | null>(null)
  const [ubicacion, setUbicacion] = useState<Ubicacion | null>(leerUbicacion)
  const [eligiendo, setEligiendo] = useState(false)
  const [mensajes, setMensajes] = useState<Mensaje[]>([])
  const final = useRef<HTMLDivElement>(null)

  useEffect(() => {
    obtenerCobertura()
      .then(setCobertura)
      .catch((e) => setErrorCarga(e instanceof ErrorConsulta ? e.message : 'No se pudo cargar la información.'))
  }, [])

  useEffect(() => {
    final.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [mensajes])

  // Una ubicación guardada que ya no tiene cobertura se descarta.
  const ubicacionValida = cobertura && ubicacion && nombreUbicacion(cobertura, ubicacion) ? ubicacion : null
  const enCurso = mensajes.some((m) => m.rol === 'asistente' && m.estado === 'cargando')

  function elegirUbicacion(nueva: Ubicacion) {
    setUbicacion(nueva)
    guardarUbicacion(nueva)
    setEligiendo(false)
    setMensajes([])
  }

  async function preguntar(pregunta: string, en: Ubicacion | null = ubicacionValida) {
    if (!en) return
    const idRespuesta = nuevoId()

    setMensajes((previos) => [
      ...previos.filter((m) => !(m.rol === 'asistente' && m.estado === 'error')),
      { id: nuevoId(), rol: 'usuario', texto: pregunta },
      { id: idRespuesta, rol: 'asistente', estado: 'cargando' },
    ])

    let actualizado: Mensaje
    try {
      const resultado = await consultar(pregunta, en)
      actualizado = { id: idRespuesta, rol: 'asistente', estado: 'listo', resultado }
    } catch (e) {
      const error = e instanceof ErrorConsulta ? e.message : 'Ocurrió un error inesperado.'
      actualizado = { id: idRespuesta, rol: 'asistente', estado: 'error', error, pregunta }
    }

    setMensajes((previos) => previos.map((m) => (m.id === idRespuesta ? actualizado : m)))
  }

  function reintentar(pregunta: string) {
    // Quita la pregunta fallida para no duplicarla.
    setMensajes((previos) => {
      const i = previos.findLastIndex((m) => m.rol === 'usuario' && m.texto === pregunta)
      return i === -1 ? previos : previos.slice(0, i)
    })
    void preguntar(pregunta)
  }

  /** Desde la sugerencia del bot: cambia al municipio y repite la pregunta sin borrar la conversación. */
  function preguntarEnMunicipio(municipioId: number, pregunta: string) {
    if (!ubicacionValida) return
    const nueva = { estadoId: ubicacionValida.estadoId, municipioId }
    setUbicacion(nueva)
    guardarUbicacion(nueva)
    void preguntar(pregunta, nueva)
  }

  return (
    <div className="flex min-h-dvh flex-col">
      <Encabezado
        ubicacion={cobertura && ubicacionValida ? nombreUbicacion(cobertura, ubicacionValida) : null}
        onCambiar={() => setEligiendo(true)}
      />

      <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4">
        {errorCarga && (
          <div className="my-auto rounded-xl border border-red-200 bg-red-50 p-4 text-center text-red-800">{errorCarga}</div>
        )}

        {!errorCarga && !cobertura && <div className="my-auto text-center text-stone-500" role="status">Cargando…</div>}

        {cobertura && (!ubicacionValida || eligiendo) && (
          <div className="my-auto py-10">
            <SelectorUbicacion
              cobertura={cobertura}
              inicial={ubicacionValida}
              onElegir={elegirUbicacion}
              onCancelar={ubicacionValida ? () => setEligiendo(false) : undefined}
            />
          </div>
        )}

        {cobertura && ubicacionValida && !eligiendo && (
          <>
            <div className="flex-1 space-y-6 py-6">
              {mensajes.length === 0 ? (
                <Bienvenida lugar={nombreUbicacion(cobertura, ubicacionValida)!} onEjemplo={preguntar} />
              ) : (
                mensajes.map((m) => (
                  <VistaMensaje key={m.id} mensaje={m} onReintentar={reintentar} onPreguntarEnMunicipio={preguntarEnMunicipio} />
                ))
              )}
              <div ref={final} />
            </div>

            <div className="sticky bottom-0 -mx-4 bg-gradient-to-t from-stone-50 from-70% to-transparent px-4 pt-6 pb-[max(1rem,env(safe-area-inset-bottom))]">
              <CampoPregunta deshabilitado={enCurso} onEnviar={preguntar} />
              <p className="mt-2 text-center text-xs text-stone-500">
                Información orientativa basada en los reglamentos publicados. No sustituye asesoría legal.
                Tus preguntas se guardan de forma anónima para mejorar el servicio.
              </p>
              {incrustado && (
                <p className="mt-1 text-center text-[11px] text-stone-400">
                  Con tecnología de{' '}
                  <a href={location.origin} target="_blank" rel="noopener" className="font-semibold text-stone-500 hover:text-stone-700">
                    Consulta Vial
                  </a>
                </p>
              )}
            </div>
          </>
        )}
      </main>
    </div>
  )
}

function Encabezado({ ubicacion, onCambiar }: { ubicacion: string | null; onCambiar: () => void }) {
  return (
    <header className="sticky top-0 z-10 border-b border-stone-200 bg-white/85 backdrop-blur">
      <div className={`mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 ${incrustado ? 'h-12' : 'h-14'}`}>
        {incrustado ? (
          // Dentro de otro sitio: sin logo ni enlace (abriría Consulta Vial dentro del iframe).
          <span className="truncate text-sm font-semibold tracking-tight">{marca}</span>
        ) : (
          <a href="/" className="flex items-center gap-2 font-semibold tracking-tight">
            <img src="/icono.svg" alt="" className="size-7" />
            Consulta Vial
          </a>
        )}
        {ubicacion && (
          <button
            type="button"
            onClick={onCambiar}
            className="flex min-w-0 items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition hover:bg-stone-50"
            title="Cambiar ubicación"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="size-4 shrink-0 text-marca-700">
              <path fillRule="evenodd" d="m9.69 18.933.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003ZM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" clipRule="evenodd" />
            </svg>
            <span className="truncate">{ubicacion}</span>
          </button>
        )}
      </div>
    </header>
  )
}

function Bienvenida({ lugar, onEjemplo }: { lugar: string; onEjemplo: (pregunta: string) => void }) {
  return (
    <div className={incrustado ? 'pt-2' : 'pt-6 sm:pt-12'}>
      <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">¿Qué quieres saber del reglamento?</h1>
      <p className="mt-2 text-stone-600">
        Pregunta sobre las reglas de tránsito en <span className="font-medium text-stone-800">{lugar}</span>.
        Cada respuesta cita los artículos en los que se basa.
      </p>
      <div className="mt-8 grid gap-2 sm:grid-cols-2">
        {EJEMPLOS.map((ejemplo) => (
          <button
            key={ejemplo}
            type="button"
            onClick={() => onEjemplo(ejemplo)}
            className="rounded-xl border border-stone-200 bg-white px-4 py-3 text-left text-sm text-stone-700 shadow-sm transition hover:border-marca-600/40 hover:bg-marca-50"
          >
            {ejemplo}
          </button>
        ))}
      </div>
    </div>
  )
}

function nombreUbicacion(cobertura: Cobertura, ubicacion: Ubicacion): string | null {
  const estado = cobertura.estados.find((e) => e.id === ubicacion.estadoId)
  if (!estado) return null
  if (ubicacion.municipioId === null) return estado.tiene_reglamento_estatal ? estado.nombre : null
  const municipio = estado.municipios.find((m) => m.id === ubicacion.municipioId)
  return municipio ? `${municipio.nombre}, ${estado.nombre}` : null
}

function leerUbicacion(): Ubicacion | null {
  // Incrustado con ?estado=…: arranca en ese estado si la persona no ha elegido otra ubicación.
  const inicial = estadoInicial ? { estadoId: estadoInicial, municipioId: null } : null
  try {
    const guardada = localStorage.getItem(CLAVE_UBICACION)
    return guardada ? (JSON.parse(guardada) as Ubicacion) : inicial
  } catch {
    return inicial
  }
}

function guardarUbicacion(ubicacion: Ubicacion) {
  try {
    localStorage.setItem(CLAVE_UBICACION, JSON.stringify(ubicacion))
  } catch {
    // Sin almacenamiento (modo privado): se volverá a pedir la ubicación.
  }
}
