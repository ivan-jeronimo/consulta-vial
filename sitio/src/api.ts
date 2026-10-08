export type EstatusDinamica = 'activa' | 'proxima' | 'terminada'

export type NombreRed = 'facebook' | 'instagram' | 'tiktok' | 'youtube'

export interface Red {
  red: NombreRed
  url: string | null
  seguidores: number | null
}

export interface Video {
  tipo: 'reel' | 'video'
  /** Reproductor oficial de Facebook (iframe). */
  embed: string
  id: number
  titulo: string
  url: string
  miniatura: string | null
  vistas: number | null
  publicado_el: string | null
}

export interface Dinamica {
  id: number
  titulo: string
  descripcion: string | null
  premio: string | null
  imagen: string | null
  url_publicacion: string | null
  inicia_el: string | null
  termina_el: string | null
  estatus: EstatusDinamica
  video_inicio: Video | null
  /** El sorteo en vivo donde se elige al ganador. */
  video_en_vivo: Video | null
  video_cierre: Video | null
  /** Solo los que aceptaron aparecer en el sitio. */
  ganadores: Ganador[]
}

export interface Ganador {
  id: number
  nombre: string
  premio: string | null
  dinamica_id: number | null
  dinamica: string | null
  foto: string | null
  fecha: string | null
}

export interface Colaboracion {
  id: number
  nombre: string
  descripcion: string | null
  logo: string | null
  url: string | null
}

export interface ContenidoSitio {
  comunidad: {
    nombre: string
    lema: string | null
    url_facebook: string | null
    logo: string | null
    portada: string | null
    redes: Red[]
  }
  estadisticas: { seguidores: number | null; dinamicas: number; premios_entregados: number }
  videos: Video[]
  dinamicas: Dinamica[]
  ganadores: Ganador[]
  colaboraciones: Colaboracion[]
}

export async function obtenerContenido(): Promise<ContenidoSitio> {
  const respuesta = await fetch('/api/sitio', { headers: { Accept: 'application/json' } })
  if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`)
  return respuesta.json()
}
