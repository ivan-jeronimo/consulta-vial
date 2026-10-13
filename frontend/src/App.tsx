import { useEffect, useRef, useState } from 'react'
import { consultar, ErrorConsulta, obtenerCobertura, obtenerCupo, type Ambito, type Cobertura, type Cupo, type Ubicacion } from './api'
import { CampoPregunta } from './components/CampoPregunta'
import { VistaMensaje, type Mensaje } from './components/Mensaje'
import { fraseAmbitos } from './components/Fuentes'
import { SelectorUbicacion } from './components/SelectorUbicacion'
import { EN_PRUEBA, estadoInicial, incrustado, marca } from './incrustado'

const CLAVE_UBICACION = 'consulta-vial:ubicacion'

// crypto.randomUUID() solo existe en contextos seguros (falla al abrir la app
// por la IP de la red local sin HTTPS); un contador basta para las keys.
let siguienteId = 0
const nuevoId = () => String(++siguienteId)

// Preguntas que sorprenden (la respuesta no es obvia). Todas tienen artículo expreso en
// el reglamento ESTATAL de Oaxaca (el chat incrustado arranca en el estado) y no chocan
// con el municipal; p. ej. "niño de 12 años adelante" o "motos entre carriles" sí chocan
// y quedan fuera. Se muestran 4 al azar en cada visita.
const EJEMPLOS = [
  '¿Tengo que hacer alto total al salir de mi cochera?',
  '¿Puedo llevar a mi perro en la bicicleta?',
  '¿Puedo llevar a un niño sentado adelante de mí en la bicicleta?',
  '¿Cuándo puede una moto subirse a la banqueta?',
  '¿Es obligatorio usar chaleco reflejante en moto?',
  '¿Puedo usar el celular mientras ando en bicicleta?',
  '¿El que va atrás en la moto también tiene que usar casco?',
  '¿Puedo andar en bicicleta por la banqueta?',
]
const MOSTRADOS = 4

export default function App() {
  const [cobertura, setCobertura] = useState<Cobertura | null>(null)
  const [errorCarga, setErrorCarga] = useState<string | null>(null)
  const [ubicacion, setUbicacion] = useState<Ubicacion | null>(leerUbicacion)
  const [eligiendo, setEligiendo] = useState(false)
  const [mensajes, setMensajes] = useState<Mensaje[]>([])
  const [cupo, setCupo] = useState<Cupo | null>(null)
  const final = useRef<HTMLDivElement>(null)

  useEffect(() => {
    obtenerCobertura()
      .then(setCobertura)
      .catch((e) => setErrorCarga(e instanceof ErrorConsulta ? e.message : 'No se pudo cargar la información.'))
    // Si falla, simplemente no se muestra el contador.
    obtenerCupo().then(setCupo).catch(() => undefined)
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
      if (resultado.cupo) setCupo(resultado.cupo)
    } catch (e) {
      const error = e instanceof ErrorConsulta ? e.message : 'Ocurrió un error inesperado.'
      if (e instanceof ErrorConsulta && e.limiteDiario) setCupo((c) => c && { ...c, restantes: 0 })
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
                <Bienvenida
                  lugar={nombreUbicacion(cobertura, ubicacionValida)!}
                  ambitos={ambitosDe(cobertura, ubicacionValida)}
                  onCambiar={() => setEligiendo(true)}
                  onEjemplo={preguntar}
                />
              ) : (
                mensajes.map((m) => (
                  <VistaMensaje key={m.id} mensaje={m} onReintentar={reintentar} onPreguntarEnMunicipio={preguntarEnMunicipio} />
                ))
              )}
              <div ref={final} />
            </div>

            <div className="sticky bottom-0 -mx-4 bg-gradient-to-t from-stone-50 from-70% to-transparent px-4 pt-6 pb-[max(1rem,env(safe-area-inset-bottom))]">
              <CampoPregunta deshabilitado={enCurso || cupo?.restantes === 0} onEnviar={preguntar} />
              {cupo && <IndicadorCupo cupo={cupo} />}
              <p className="mt-1.5 text-center text-xs text-stone-500">
                {EN_PRUEBA && <span className="font-semibold text-amber-700">Versión de prueba: puede equivocarse. </span>}
                Información orientativa basada en los reglamentos publicados; revisa los artículos citados. No sustituye asesoría legal.
                Tus preguntas se guardan de forma anónima para mejorar el servicio.
              </p>
            </div>
          </>
        )}
      </main>
    </div>
  )
}

function Encabezado({ ubicacion, onCambiar }: { ubicacion: string | null; onCambiar: () => void }) {
  return (
    <header className="sticky top-0 z-10 border-b border-stone-200 bg-white/85 backdrop-blur senal:border-senal-borde senal:bg-senal-fondo/90">
      <div className={`mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 ${incrustado ? 'h-12' : 'h-14'}`}>
        {incrustado ? (
          // Dentro de otro sitio: sin logo ni enlace (abriría Consulta Vial dentro del iframe).
          <span className="flex min-w-0 items-center gap-2">
            <span className="truncate text-sm font-semibold tracking-tight senal:font-titulos senal:text-base senal:font-bold senal:uppercase senal:tracking-wider">{marca}</span>
            {EN_PRUEBA && <InsigniaPrueba />}
          </span>
        ) : (
          <a href="/" className="flex items-center gap-2 font-semibold tracking-tight">
            <img src="/icono.svg" alt="" className="size-7" />
            Consulta Vial
            {EN_PRUEBA && <InsigniaPrueba />}
          </a>
        )}
        {ubicacion && (
          <button
            type="button"
            onClick={onCambiar}
            className="flex min-w-0 items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition hover:bg-stone-50 senal:border-senal-oscuro/20 senal:font-semibold senal:text-senal-oscuro senal:shadow-none senal:hover:border-senal-oscuro"
            title="Cambiar ubicación"
          >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="size-4 shrink-0 text-marca-700 senal:text-senal-oscuro">
              <path fillRule="evenodd" d="m9.69 18.933.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003ZM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" clipRule="evenodd" />
            </svg>
            <span className="truncate">{ubicacion}</span>
          </button>
        )}
      </div>
    </header>
  )
}

function Bienvenida({
  lugar,
  ambitos,
  onCambiar,
  onEjemplo,
}: {
  lugar: string
  ambitos: Ambito[]
  onCambiar: () => void
  onEjemplo: (pregunta: string) => void
}) {
  // Al azar una vez por visita (no cambian mientras la persona lee).
  const [ejemplos] = useState(() => [...EJEMPLOS].sort(() => Math.random() - 0.5).slice(0, MOSTRADOS))

  return (
    <div className={incrustado ? 'pt-2' : 'pt-6 sm:pt-12'}>
      <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl senal:font-titulos senal:text-[2.1rem] senal:font-extrabold senal:uppercase senal:leading-[0.95] senal:tracking-normal">
        ¿Qué quieres saber del reglamento?
      </h1>
      {/* Qué se consulta, dicho en una frase: con municipio, también el estatal. */}
      <p className="mt-2 text-stone-600">
        {ambitos.length > 0 ? (
          <>
            Respondo con <span className="font-medium text-stone-800 senal:text-senal-oscuro">{fraseAmbitos(ambitos)}</span>, y cito los
            artículos en que me baso.
          </>
        ) : (
          <>
            Pregunta sobre las reglas de tránsito en <span className="font-medium text-stone-800">{lugar}</span>. Cada respuesta cita los
            artículos en los que se basa.
          </>
        )}{' '}
        <button type="button" onClick={onCambiar} className="font-medium text-marca-700 underline underline-offset-2 senal:font-bold senal:text-senal-oscuro senal:decoration-senal-acento senal:decoration-2">
          Cambiar lugar
        </button>
      </p>
      {EN_PRUEBA && (
        <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 senal:border-l-4 senal:border-senal-acento senal:bg-white senal:text-stone-700">
          <span className="font-semibold">Estamos probando este asistente.</span> Puede equivocarse; por ejemplo, al decidir qué
          aplica primero entre el reglamento municipal, el estatal y la guía de examen. Revisa siempre los artículos que cita.
        </p>
      )}
      <div className="mt-8 grid gap-2 sm:grid-cols-2">
        {ejemplos.map((ejemplo, i) => (
          <button
            key={ejemplo}
            type="button"
            onClick={() => onEjemplo(ejemplo)}
            className="rounded-xl border border-stone-200 bg-white px-4 py-3 text-left text-sm text-stone-700 shadow-sm transition hover:border-marca-600/40 hover:bg-marca-50 senal:flex senal:items-center senal:gap-3 senal:border-senal-borde senal:font-medium senal:text-senal-oscuro senal:shadow-none senal:hover:border-senal-oscuro senal:hover:bg-white"
          >
            {/* Con el tema señalética, cada sugerencia lleva su número como marcador de kilómetro. */}
            <span aria-hidden="true" className="hidden shrink-0 rounded-md bg-senal-oscuro px-1.5 py-0.5 font-titulos text-sm font-extrabold text-senal-acento senal:inline">
              {String(i + 1).padStart(2, '0')}
            </span>
            {ejemplo}
          </button>
        ))}
      </div>
    </div>
  )
}

/** "Te quedan 3 de 5 preguntas hoy", con una marca por pregunta (como rayas del carril). */
function IndicadorCupo({ cupo }: { cupo: Cupo }) {
  if (cupo.limite <= 0) return null
  const usadas = cupo.limite - cupo.restantes

  return (
    <div className="mt-2 flex items-center justify-center gap-2 text-xs text-stone-500" aria-live="polite">
      {cupo.restantes === 0 ? (
        <span className="font-medium text-amber-800">Llegaste a tus {cupo.limite} preguntas de hoy. Vuelve mañana.</span>
      ) : (
        <span>
          Te {cupo.restantes === 1 ? 'queda' : 'quedan'} <span className="font-semibold text-stone-700 senal:text-senal-oscuro">{cupo.restantes}</span> de{' '}
          {cupo.limite} preguntas hoy
        </span>
      )}
      <span aria-hidden="true" className="flex gap-0.5">
        {Array.from({ length: cupo.limite }, (_, i) => (
          <span
            key={i}
            className={`h-1.5 w-3 rounded-full ${i < usadas ? 'bg-stone-300 senal:bg-senal-borde' : 'bg-marca-600 senal:bg-senal-acento'}`}
          />
        ))}
      </span>
    </div>
  )
}

/** Reglamentos que se consultan en la ubicación elegida: con municipio, también el estatal. */
function ambitosDe(cobertura: Cobertura, ubicacion: Ubicacion): Ambito[] {
  const estado = cobertura.estados.find((e) => e.id === ubicacion.estadoId)
  const municipio = estado?.municipios.find((m) => m.id === ubicacion.municipioId)

  return [
    municipio && { nivel: 'municipal' as const, nombre: municipio.nombre },
    estado?.tiene_reglamento_estatal && { nivel: 'estatal' as const, nombre: estado.nombre },
    cobertura.hay_normativa_federal && { nivel: 'federal' as const, nombre: 'México' },
  ].filter((a): a is Ambito => !!a)
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

function InsigniaPrueba() {
  return (
    <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-800 senal:bg-senal-oscuro senal:text-senal-acento">
      En prueba
    </span>
  )
}
