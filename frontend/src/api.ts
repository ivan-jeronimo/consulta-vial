import { origen } from './incrustado'

export type Nivel = 'federal' | 'estatal' | 'municipal'
export type TipoDocumento = 'reglamento' | 'ley' | 'manual' | 'otro'

export interface Municipio {
  id: number
  nombre: string
}

export interface Estado {
  id: number
  nombre: string
  tiene_reglamento_estatal: boolean
  municipios: Municipio[]
}

export interface Cobertura {
  hay_normativa_federal: boolean
  estados: Estado[]
}

export interface Fuente {
  documento_id: number
  reglamento: string
  tipo: TipoDocumento
  nivel: Nivel
  anio: number
  capitulo: string | null
  seccion: string | null
  articulo: string | null
  fraccion: string | null
  inciso: string | null
  /** Posición en el documento, para mostrar los apartados en el orden del texto. */
  orden: number
  relevancia: number
  /** Encabezado del artículo cuando la fuente es solo un apartado (fracción/inciso). */
  contexto: string | null
  texto: string
}

export interface Cupo {
  /** Preguntas al día por persona. */
  limite: number
  restantes: number
}

export interface Ambito {
  nivel: Nivel
  /** El lugar: municipio, estado o "México". */
  nombre: string
}

export interface RespuestaConsulta {
  /** Para valorar la respuesta (👍/👎). */
  consulta_id: string
  pregunta: string
  ubicacion: string
  /** Reglamentos que se consultaron (con municipio: el municipal y también el estatal). */
  ambitos: Ambito[]
  /** Preguntas que le quedan hoy, contando esta. */
  cupo?: Cupo
  respuesta: string | null
  aviso: string | null
  modelo: string | null
  /** Municipios del estado con reglamento propio, cuando el estatal no respondió la pregunta. */
  sugerir_municipios: Municipio[]
  fuentes: Fuente[]
}

export interface Ubicacion {
  estadoId: number
  municipioId: number | null
}

/** Error con un mensaje ya listo para mostrarse al usuario. */
export class ErrorConsulta extends Error {
  /** La persona ya usó todas sus preguntas de hoy. */
  limiteDiario = false
}

async function pedir<T>(url: string, init?: RequestInit): Promise<T> {
  let respuesta: Response

  try {
    respuesta = await fetch(url, {
      ...init,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init?.headers },
    })
  } catch {
    throw new ErrorConsulta('No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.')
  }

  if (respuesta.ok) {
    return respuesta.json() as Promise<T>
  }

  if (respuesta.status === 429) {
    // El límite diario trae su propio mensaje; el de por minuto, no.
    const cuerpo = await respuesta.json().catch(() => null)
    const error = new ErrorConsulta(
      cuerpo?.limite_diario ? cuerpo.message : 'Hiciste muchas consultas seguidas. Espera un minuto e inténtalo de nuevo.',
    )
    error.limiteDiario = !!cuerpo?.limite_diario
    throw error
  }

  if (respuesta.status === 422) {
    const cuerpo = await respuesta.json().catch(() => null)
    const primerError = cuerpo?.errors && Object.values<string[]>(cuerpo.errors)[0]?.[0]
    throw new ErrorConsulta(primerError ?? cuerpo?.message ?? 'Revisa los datos de tu pregunta.')
  }

  throw new ErrorConsulta('Ocurrió un problema al consultar el reglamento. Inténtalo más tarde.')
}

export function obtenerCobertura(): Promise<Cobertura> {
  return pedir('/api/territorios')
}

export function consultar(pregunta: string, ubicacion: Ubicacion): Promise<RespuestaConsulta> {
  return pedir('/api/consulta', {
    method: 'POST',
    body: JSON.stringify({
      pregunta,
      estado_id: ubicacion.estadoId,
      municipio_id: ubicacion.municipioId,
      visitante: idVisitante(),
      origen,
    }),
  })
}

export function obtenerCupo(): Promise<Cupo> {
  const visitante = idVisitante()
  return pedir(`/api/consultas/cupo${visitante ? `?visitante=${visitante}` : ''}`)
}

export function valorar(consultaId: string, util: boolean): Promise<unknown> {
  return pedir(`/api/consultas/${consultaId}/valoracion`, {
    method: 'POST',
    body: JSON.stringify({ util }),
  })
}

const CLAVE_VISITANTE = 'consulta-vial:visitante'

/**
 * Identificador aleatorio y anónimo de este navegador, solo para contar
 * usuarios únicos en las métricas. No se relaciona con ningún dato personal.
 */
function idVisitante(): string | undefined {
  try {
    let id = localStorage.getItem(CLAVE_VISITANTE)
    if (!id) {
      id = uuidAleatorio()
      localStorage.setItem(CLAVE_VISITANTE, id)
    }
    return id
  } catch {
    return undefined // sin almacenamiento (modo privado): la consulta se registra sin visitante
  }
}

/** UUID v4. crypto.getRandomValues sí existe fuera de HTTPS (randomUUID no). */
function uuidAleatorio(): string {
  const b = crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}
