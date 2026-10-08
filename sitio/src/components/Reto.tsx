import { useCallback, useEffect, useState } from 'react'
import { compacto } from '../formato'
import { ErrorReto, reto, type EstadoReto, type Ranking, type ResumenMes } from '../reto'
import { Partida } from './Partida'
import { IconoRed } from './Redes'

// El chat vial vive aparte (frontend/, marca blanca); aquí solo se enlaza.
const URL_CHAT: string = import.meta.env.VITE_URL_CHAT ?? 'http://localhost:5173'

const AVISOS_LOGIN: Record<string, string> = {
  cancelado: 'No se completó el inicio de sesión con Facebook.',
  error: 'No pudimos iniciar tu sesión con Facebook. Intenta de nuevo.',
}

export function Reto() {
  const [estado, setEstado] = useState<EstadoReto | null>(null)
  const [ranking, setRanking] = useState<Ranking | null>(null)
  const [jugando, setJugando] = useState(false)
  const [aviso, setAviso] = useState<string | null>(() => AVISOS_LOGIN[new URLSearchParams(location.search).get('login') ?? ''] ?? null)

  const cargar = useCallback(() => {
    reto.estado().then(setEstado).catch(() => setEstado(null))
    reto.ranking().then(setRanking).catch(() => setRanking(null))
  }, [])

  useEffect(() => {
    cargar()
    // Quita ?login=… de la barra de direcciones para que no reaparezca el aviso al recargar.
    if (location.search.includes('login=')) history.replaceState(null, '', location.pathname + location.hash)
  }, [cargar])

  const accion = async (fn: () => Promise<unknown>) => {
    try {
      await fn()
      cargar()
    } catch (e) {
      setAviso(e instanceof ErrorReto ? e.message : 'Algo falló. Intenta de nuevo.')
    }
  }

  const jugador = estado?.jugador

  return (
    <section id="reto" className="scroll-mt-14 bg-asfalto-900 text-white">
      <div className="mx-auto max-w-5xl px-4 py-12">
        <div className="grid gap-8 lg:grid-cols-[1fr_22rem]">
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-linea">Simulador del examen de manejo</p>
            <h2 className="mt-1 text-2xl font-black tracking-tight sm:text-3xl">El reto del asfalto</h2>
            <p className="mt-3 max-w-xl text-stone-300">
              10 preguntas de la Guía de Examen de Manejo de Oaxaca y del reglamento de tránsito. 20 segundos por pregunta:
              entre más rápido aciertes, más puntos. Cada mes, el ranking se reinicia.
            </p>

            {aviso && (
              <p role="alert" className="mt-4 flex items-start justify-between gap-3 rounded-xl bg-red-500/15 px-4 py-3 text-sm text-red-200 ring-1 ring-red-400/30">
                {aviso}
                <button type="button" onClick={() => setAviso(null)} aria-label="Cerrar aviso" className="text-red-200/70 hover:text-white">✕</button>
              </p>
            )}

            <div className="mt-6">
              {!estado ? (
                <div className="h-28 max-w-md animate-pulse rounded-2xl bg-asfalto-800" aria-hidden />
              ) : !jugador ? (
                <SinSesion loginDisponible={estado.login_disponible} />
              ) : jugador.publico === null ? (
                <Consentimiento onElegir={(publico) => accion(() => reto.preferencias(publico))} />
              ) : (
                <TarjetaJugador
                  estado={estado}
                  onJugar={() => setJugando(true)}
                  onPrivacidad={() => accion(() => reto.preferencias(!jugador.publico))}
                  onSalir={() => accion(reto.salir)}
                  onBorrar={() => {
                    if (confirm('¿Borrar tu cuenta del reto? Se eliminan tus partidas y tu foto. No se puede deshacer.')) accion(reto.borrarCuenta)
                  }}
                />
              )}
            </div>

            <p className="mt-8 text-sm text-stone-400">
              ¿Tienes una duda del reglamento?{' '}
              <a href={URL_CHAT} className="font-semibold text-linea underline decoration-2 underline-offset-2 hover:text-yellow-300">
                Pregúntale al asistente vial
              </a>
            </p>
          </div>

          <TablaRanking ranking={ranking} />
        </div>
      </div>

      {jugando && (
        <Partida
          onCerrar={() => {
            setJugando(false)
            cargar()
          }}
        />
      )}
    </section>
  )
}

function SinSesion({ loginDisponible }: { loginDisponible: boolean }) {
  if (!loginDisponible) return <p className="text-stone-400">El reto abre muy pronto.</p>

  return (
    <div>
      <a
        href="/auth/facebook"
        className="inline-flex items-center gap-3 rounded-full bg-[#0866FF] px-6 py-3 font-bold text-white shadow-lg hover:bg-[#0756d6]"
      >
        <IconoRed red="facebook" className="size-5" />
        Entrar con Facebook para jugar
      </a>
      <p className="mt-3 max-w-md text-xs text-stone-400">
        Solo usamos tu nombre y foto para el ranking (si lo aceptas) y tu correo para avisarte si ganas algo. No publicamos nada en tu
        Facebook.
      </p>
    </div>
  )
}

function Consentimiento({ onElegir }: { onElegir: (publico: boolean) => void }) {
  return (
    <div className="max-w-md rounded-2xl bg-asfalto-800 p-5 ring-1 ring-white/10">
      <p className="font-bold">¿Quieres aparecer en el ranking?</p>
      <p className="mt-1 text-sm text-stone-300">
        Mostraríamos tu nombre y foto de Facebook junto a tu puntaje. Puedes cambiarlo cuando quieras.
      </p>
      <div className="mt-4 flex flex-wrap gap-2">
        <button type="button" onClick={() => onElegir(true)} className="rounded-full bg-linea px-5 py-2 font-bold text-asfalto-900 hover:bg-yellow-300">
          Sí, mostrarme
        </button>
        <button type="button" onClick={() => onElegir(false)} className="rounded-full bg-white/10 px-5 py-2 font-semibold hover:bg-white/20">
          Jugar en privado
        </button>
      </div>
    </div>
  )
}

function TarjetaJugador({
  estado,
  onJugar,
  onPrivacidad,
  onSalir,
  onBorrar,
}: {
  estado: EstadoReto
  onJugar: () => void
  onPrivacidad: () => void
  onSalir: () => void
  onBorrar: () => void
}) {
  const jugador = estado.jugador!

  return (
    <div className="max-w-md">
      <div className="flex items-center gap-4 rounded-2xl bg-asfalto-800 p-4 ring-1 ring-white/10">
        <Foto nombre={jugador.nombre} foto={jugador.foto} className="size-14" />
        <div className="min-w-0">
          <p className="truncate font-bold">{jugador.nombre}</p>
          <TuMes mes={estado.mes} />
        </div>
      </div>

      <button
        type="button"
        onClick={onJugar}
        disabled={!estado.se_puede_jugar}
        className="mt-4 w-full rounded-full bg-linea px-6 py-3 text-lg font-black text-asfalto-900 shadow-lg transition hover:bg-yellow-300 disabled:cursor-not-allowed disabled:bg-stone-600 disabled:text-stone-300 sm:w-auto"
      >
        {estado.mes ? 'Jugar otra vez' : 'Empezar el reto'}
      </button>
      {!estado.se_puede_jugar && <p className="mt-2 text-sm text-stone-400">Estamos preparando las preguntas. ¡Vuelve pronto!</p>}

      <p className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-400">
        <button type="button" onClick={onPrivacidad} className="underline underline-offset-2 hover:text-white">
          {jugador.publico ? 'Ocultarme del ranking' : 'Aparecer en el ranking'}
        </button>
        <button type="button" onClick={onSalir} className="underline underline-offset-2 hover:text-white">Cerrar sesión</button>
        <button type="button" onClick={onBorrar} className="underline underline-offset-2 hover:text-red-300">Borrar mis datos</button>
      </p>
    </div>
  )
}

function TuMes({ mes }: { mes: ResumenMes | null }) {
  if (!mes) return <p className="text-sm text-stone-400">Aún no juegas este mes.</p>

  return (
    <p className="text-sm text-stone-300">
      Tu mejor del mes: <span className="font-bold text-linea">{mes.puntos.toLocaleString('es-MX')} pts</span>
      {mes.posicion ? ` · lugar #${mes.posicion}` : ' · fuera del ranking'}
    </p>
  )
}

function TablaRanking({ ranking }: { ranking: Ranking | null }) {
  return (
    <aside className="rounded-2xl bg-asfalto-800 p-5 ring-1 ring-white/10" aria-labelledby="titulo-ranking">
      <h3 id="titulo-ranking" className="flex items-baseline justify-between gap-2">
        <span className="font-black">Ranking</span>
        {ranking && <span className="text-xs capitalize text-stone-400">{ranking.mes}</span>}
      </h3>

      {!ranking ? (
        <div className="mt-4 space-y-2" aria-hidden>
          {[0, 1, 2].map((i) => <div key={i} className="h-10 animate-pulse rounded-lg bg-asfalto-700" />)}
        </div>
      ) : ranking.jugadores.length === 0 ? (
        <p className="mt-4 text-sm text-stone-400">Nadie ha jugado este mes. ¡El primer lugar está libre!</p>
      ) : (
        <ol className="mt-3 space-y-1">
          {ranking.jugadores.map((j) => (
            <li key={j.posicion} className={`flex items-center gap-3 rounded-lg px-2 py-1.5 ${j.posicion <= 3 ? 'bg-white/5' : ''}`}>
              <span className={`w-6 text-center text-sm font-black ${j.posicion === 1 ? 'text-linea' : 'text-stone-400'}`}>
                {j.posicion <= 3 ? ['🥇', '🥈', '🥉'][j.posicion - 1] : j.posicion}
              </span>
              <Foto nombre={j.nombre} foto={j.foto} className="size-8 text-xs" />
              <span className="min-w-0 flex-1 truncate text-sm">{j.nombre}</span>
              <span className="text-sm font-bold tabular-nums text-linea">{compacto(j.puntos)}</span>
            </li>
          ))}
        </ol>
      )}
    </aside>
  )
}

export function Foto({ nombre, foto, className }: { nombre: string; foto: string | null; className: string }) {
  return foto ? (
    <img src={foto} alt="" className={`${className} shrink-0 rounded-full object-cover`} />
  ) : (
    <span className={`${className} grid shrink-0 place-items-center rounded-full bg-linea font-bold text-asfalto-900`}>
      {nombre.split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('')}
    </span>
  )
}
