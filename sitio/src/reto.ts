/** API del reto. Usa la sesión (cookie) de Laravel: mismo origen gracias al proxy. */

export interface ResumenMes {
  puntos: number
  aciertos: number
  /** null si eligió no aparecer en el ranking. */
  posicion: number | null
  partidas: number
}

export interface EstadoReto {
  login_disponible: boolean
  se_puede_jugar: boolean
  jugador: { nombre: string; foto: string | null; publico: boolean | null } | null
  mes: ResumenMes | null
}

export interface FilaRanking {
  posicion: number
  nombre: string
  foto: string | null
  puntos: number
  aciertos: number
}

export interface Ranking {
  mes: string
  jugadores: FilaRanking[]
}

export interface PreguntaJuego {
  numero: number
  total: number
  categoria: string
  pregunta: string
  opciones: string[]
  restante_ms: number
  limite_ms: number
}

export interface ResultadoRespuesta {
  correcta: boolean
  fuera_de_tiempo: boolean
  opcion_correcta: number
  explicacion: string | null
  cita: string | null
  puntos: number
  puntos_partida: number
  aciertos: number
  terminada: boolean
  siguiente: PreguntaJuego | null
  mes: ResumenMes | null
  /** Enlace para compartir (solo al terminar). */
  compartir: string | null
}

export class ErrorReto extends Error {
  constructor(
    message: string,
    public estado: number,
  ) {
    super(message)
  }
}

/** Laravel deja el token CSRF en la cookie XSRF-TOKEN y lo espera de vuelta en este encabezado. */
function tokenCsrf(): string {
  const valor = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return valor ? decodeURIComponent(valor) : ''
}

async function pedir<T>(ruta: string, opciones: { metodo?: 'GET' | 'POST'; cuerpo?: unknown } = {}): Promise<T> {
  const metodo = opciones.metodo ?? 'GET'
  const respuesta = await fetch(`/api/reto/${ruta}`, {
    method: metodo,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(metodo === 'POST' ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': tokenCsrf() } : {}),
    },
    body: opciones.cuerpo === undefined ? undefined : JSON.stringify(opciones.cuerpo),
  })

  if (!respuesta.ok) {
    const datos = await respuesta.json().catch(() => null)
    const mensaje =
      respuesta.status === 401 ? 'Tu sesión terminó. Vuelve a entrar con Facebook.'
      : respuesta.status === 419 ? 'La página estuvo abierta mucho tiempo. Recárgala e intenta de nuevo.'
      : respuesta.status === 429 ? 'Vas muy rápido. Espera un momento.'
      : (datos?.message as string | undefined) ?? 'Algo falló. Intenta de nuevo.'
    throw new ErrorReto(mensaje, respuesta.status)
  }

  return respuesta.json()
}

export const reto = {
  estado: () => pedir<EstadoReto>('yo'),
  ranking: () => pedir<Ranking>('ranking'),
  preferencias: (publico: boolean) => pedir('preferencias', { metodo: 'POST', cuerpo: { publico } }),
  iniciar: () => pedir<{ partida: string; pregunta: PreguntaJuego }>('partidas', { metodo: 'POST' }),
  responder: (partida: string, opcion: number | null) =>
    pedir<ResultadoRespuesta>(`partidas/${partida}/respuestas`, { metodo: 'POST', cuerpo: { opcion } }),
  salir: () => pedir('salir', { metodo: 'POST' }),
  borrarCuenta: () => pedir('borrar-cuenta', { metodo: 'POST' }),
}
