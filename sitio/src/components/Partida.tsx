import { useCallback, useEffect, useRef, useState } from 'react'
import { ErrorReto, reto, type PreguntaJuego, type ResultadoRespuesta } from '../reto'

type Fase =
  | { tipo: 'cargando' }
  | { tipo: 'pregunta'; pregunta: PreguntaJuego; elegida: number | null; resultado: ResultadoRespuesta | null }
  | { tipo: 'final'; resultado: ResultadoRespuesta; total: number }
  | { tipo: 'error'; mensaje: string }

const LETRAS = ['A', 'B', 'C', 'D']

/** Una partida de 10 preguntas en un modal (pantalla completa en el celular). */
export function Partida({ onCerrar }: { onCerrar: () => void }) {
  const dialogo = useRef<HTMLDialogElement>(null)
  const [partida, setPartida] = useState<string | null>(null)
  const [fase, setFase] = useState<Fase>({ tipo: 'cargando' })
  const [puntos, setPuntos] = useState(0)
  const enviando = useRef(false)

  const empezar = useCallback(async () => {
    setFase({ tipo: 'cargando' })
    setPuntos(0)
    try {
      const { partida, pregunta } = await reto.iniciar()
      setPartida(partida)
      setFase({ tipo: 'pregunta', pregunta, elegida: null, resultado: null })
    } catch (e) {
      setFase({ tipo: 'error', mensaje: e instanceof ErrorReto ? e.message : 'No pudimos empezar la partida.' })
    }
  }, [])

  useEffect(() => {
    dialogo.current?.showModal()
    empezar()
  }, [empezar])

  const responder = useCallback(
    async (opcion: number | null) => {
      if (!partida || fase.tipo !== 'pregunta' || fase.resultado || enviando.current) return
      enviando.current = true
      setFase({ ...fase, elegida: opcion })
      try {
        const resultado = await reto.responder(partida, opcion)
        setPuntos(resultado.puntos_partida)
        setFase({ ...fase, elegida: opcion, resultado })
      } catch (e) {
        setFase({ tipo: 'error', mensaje: e instanceof ErrorReto ? e.message : 'Se perdió la conexión.' })
      } finally {
        enviando.current = false
      }
    },
    [partida, fase],
  )

  const siguiente = () => {
    if (fase.tipo !== 'pregunta' || !fase.resultado) return
    if (fase.resultado.siguiente) setFase({ tipo: 'pregunta', pregunta: fase.resultado.siguiente, elegida: null, resultado: null })
    else setFase({ tipo: 'final', resultado: fase.resultado, total: fase.pregunta.total })
  }

  return (
    <dialog
      ref={dialogo}
      onClose={onCerrar}
      aria-label="Partida del reto"
      className="hoja m-0 h-dvh max-h-none w-screen max-w-none bg-stone-50 p-0 text-stone-900 backdrop:bg-asfalto-950/80 sm:m-auto sm:h-auto sm:max-h-[92dvh] sm:max-w-xl sm:rounded-3xl"
    >
      <div className="flex h-full flex-col sm:max-h-[92dvh]">
        <header className="flex items-center justify-between gap-3 bg-asfalto-900 px-4 py-3 text-white">
          <p className="text-sm font-bold">
            {fase.tipo === 'pregunta' ? `Pregunta ${fase.pregunta.numero} de ${fase.pregunta.total}` : 'El reto del asfalto'}
          </p>
          <div className="flex items-center gap-3">
            <span className="rounded-full bg-linea px-3 py-0.5 text-sm font-black tabular-nums text-asfalto-900">{puntos} pts</span>
            <button
              type="button"
              onClick={() => (fase.tipo === 'final' || confirm('¿Salir? Esta partida no contará.')) && dialogo.current?.close()}
              aria-label="Salir"
              className="grid size-8 place-items-center rounded-full text-stone-300 hover:bg-white/10 hover:text-white"
            >
              ✕
            </button>
          </div>
        </header>
        <div className="linea-carretera shrink-0" />

        <div className="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
          {fase.tipo === 'cargando' && <p className="py-16 text-center text-stone-500">Preparando tus preguntas…</p>}

          {fase.tipo === 'error' && (
            <div className="py-12 text-center">
              <p className="text-stone-700">{fase.mensaje}</p>
              <button type="button" onClick={() => dialogo.current?.close()} className="mt-4 rounded-full bg-asfalto-900 px-5 py-2 font-semibold text-white">
                Cerrar
              </button>
            </div>
          )}

          {fase.tipo === 'pregunta' && (
            <VistaPregunta
              key={fase.pregunta.numero}
              pregunta={fase.pregunta}
              elegida={fase.elegida}
              resultado={fase.resultado}
              onResponder={responder}
              onSiguiente={siguiente}
            />
          )}

          {fase.tipo === 'final' && <Final resultado={fase.resultado} total={fase.total} onOtra={empezar} onCerrar={() => dialogo.current?.close()} />}
        </div>
      </div>
    </dialog>
  )
}

function VistaPregunta({
  pregunta,
  elegida,
  resultado,
  onResponder,
  onSiguiente,
}: {
  pregunta: PreguntaJuego
  elegida: number | null
  resultado: ResultadoRespuesta | null
  onResponder: (opcion: number | null) => void
  onSiguiente: () => void
}) {
  const restante = useCuentaRegresiva(pregunta.restante_ms, !resultado && elegida === null, () => onResponder(null))
  const proporcion = restante / pregunta.limite_ms
  const botonSiguiente = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (resultado) botonSiguiente.current?.focus()
  }, [resultado])

  return (
    <div>
      {/* Reloj: barra que se vacía; en rojo los últimos 5 segundos. */}
      <div className="h-2 overflow-hidden rounded-full bg-stone-200" role="timer" aria-label={`${Math.ceil(restante / 1000)} segundos`}>
        <div
          className={`h-full rounded-full transition-[width] duration-100 ease-linear ${restante <= 5000 ? 'bg-red-500' : 'bg-linea-oscura'}`}
          style={{ width: `${proporcion * 100}%` }}
        />
      </div>
      <div className="mt-2 flex justify-between text-xs text-stone-500">
        <span className="font-semibold">{pregunta.categoria}</span>
        <span className="tabular-nums">{Math.ceil(restante / 1000)} s</span>
      </div>

      <h3 className="mt-4 text-xl font-bold leading-snug">{pregunta.pregunta}</h3>

      <ul className="mt-5 space-y-2.5">
        {pregunta.opciones.map((texto, i) => {
          const esCorrecta = resultado && i === resultado.opcion_correcta
          const esElegidaMal = resultado && i === elegida && !resultado.correcta
          const estilo = esCorrecta
            ? 'bg-green-50 ring-2 ring-green-600 text-green-900'
            : esElegidaMal
              ? 'bg-red-50 ring-2 ring-red-500 text-red-900'
              : resultado
                ? 'bg-white ring-1 ring-stone-200 text-stone-400'
                : elegida === i
                  ? 'bg-yellow-50 ring-2 ring-linea-oscura'
                  : 'bg-white ring-1 ring-stone-300 hover:ring-2 hover:ring-asfalto-900'

          return (
            <li key={i}>
              <button
                type="button"
                disabled={!!resultado || elegida !== null}
                onClick={() => onResponder(i)}
                className={`flex w-full items-start gap-3 rounded-2xl px-4 py-3 text-left transition ${estilo}`}
              >
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-asfalto-900 text-xs font-black text-linea">{LETRAS[i]}</span>
                <span className="pt-0.5">{texto}</span>
                {esCorrecta && <span className="ml-auto pt-0.5 font-bold text-green-700" aria-label="correcta">✓</span>}
                {esElegidaMal && <span className="ml-auto pt-0.5 font-bold text-red-600" aria-label="incorrecta">✗</span>}
              </button>
            </li>
          )
        })}
      </ul>

      {resultado && (
        <div className="mt-5" aria-live="polite">
          <p className={`font-black ${resultado.correcta ? 'text-green-700' : 'text-red-600'}`}>
            {resultado.correcta ? `¡Correcto! +${resultado.puntos} pts` : resultado.fuera_de_tiempo && elegida === null ? '¡Se acabó el tiempo!' : 'Incorrecto'}
          </p>
          {resultado.explicacion && <p className="mt-1 text-sm text-stone-700">{resultado.explicacion}</p>}
          {resultado.cita && <p className="mt-1 text-xs text-stone-500">Fuente: {resultado.cita}</p>}
          <button
            ref={botonSiguiente}
            type="button"
            onClick={onSiguiente}
            className="mt-5 w-full rounded-full bg-asfalto-900 px-6 py-3 font-bold text-white hover:bg-asfalto-700"
          >
            {resultado.siguiente ? 'Siguiente pregunta' : 'Ver mi resultado'}
          </button>
        </div>
      )}
    </div>
  )
}

function Final({ resultado, total, onOtra, onCerrar }: { resultado: ResultadoRespuesta; total: number; onOtra: () => void; onCerrar: () => void }) {
  const mensaje =
    resultado.aciertos === total ? '¡Perfecto! Estás listo para el examen.'
    : resultado.aciertos >= 8 ? '¡Muy bien! Casi perfecto.'
    : resultado.aciertos >= 6 ? 'Vas bien, pero repasa un poco más.'
    : 'Te conviene estudiar la guía antes del examen.'

  return (
    <div className="py-6 text-center">
      <p className="text-sm font-bold uppercase tracking-wider text-stone-500">Tu resultado</p>
      <p className="mt-2 text-6xl font-black tabular-nums">{resultado.puntos_partida.toLocaleString('es-MX')}</p>
      <p className="text-stone-500">puntos</p>
      <p className="mt-4 text-lg font-bold">
        {resultado.aciertos} de {total} correctas
      </p>
      <p className="mt-1 text-stone-600">{mensaje}</p>

      {resultado.mes && (
        <p className="mx-auto mt-5 max-w-xs rounded-2xl bg-asfalto-900 px-4 py-3 text-sm text-white">
          Tu mejor del mes: <span className="font-black text-linea">{resultado.mes.puntos.toLocaleString('es-MX')} pts</span>
          {resultado.mes.posicion && (
            <>
              <br />
              Vas en el lugar <span className="font-black text-linea">#{resultado.mes.posicion}</span> del ranking
            </>
          )}
        </p>
      )}

      <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <button type="button" onClick={onOtra} className="rounded-full bg-linea px-6 py-3 font-black text-asfalto-900 hover:bg-yellow-300">
          Jugar otra vez
        </button>
        <button type="button" onClick={onCerrar} className="rounded-full bg-stone-200 px-6 py-3 font-semibold hover:bg-stone-300">
          Ver el ranking
        </button>
      </div>
    </div>
  )
}

/** Cuenta regresiva en ms; al llegar a 0 llama a `alTerminar` una sola vez. */
function useCuentaRegresiva(inicial: number, activa: boolean, alTerminar: () => void) {
  const [restante, setRestante] = useState(inicial)
  const fin = useRef(performance.now() + inicial)
  const avisado = useRef(false)
  const callback = useRef(alTerminar)
  callback.current = alTerminar

  useEffect(() => {
    if (!activa) return
    const id = setInterval(() => {
      const quedan = Math.max(0, fin.current - performance.now())
      setRestante(quedan)
      if (quedan === 0 && !avisado.current) {
        avisado.current = true
        callback.current()
      }
    }, 100)
    return () => clearInterval(id)
  }, [activa])

  return restante
}
