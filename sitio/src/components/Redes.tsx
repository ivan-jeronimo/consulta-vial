import { siFacebook, siInstagram, siTiktok, siYoutube } from 'simple-icons'
import type { NombreRed, Red } from '../api'
import { compacto } from '../formato'

const ICONOS: Record<NombreRed, { nombre: string; path: string }> = {
  facebook: { nombre: 'Facebook', path: siFacebook.path },
  instagram: { nombre: 'Instagram', path: siInstagram.path },
  tiktok: { nombre: 'TikTok', path: siTiktok.path },
  youtube: { nombre: 'YouTube', path: siYoutube.path },
}

export function IconoRed({ red, className = 'size-4' }: { red: NombreRed; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden>
      <path d={ICONOS[red].path} />
    </svg>
  )
}

/** Desglose de seguidores por red (en el encabezado). */
export function SeguidoresPorRed({ redes }: { redes: Red[] }) {
  if (redes.length < 2) return null

  return (
    <ul className="mt-3 flex flex-wrap gap-2" aria-label="Seguidores por red social">
      {redes.map((r) => {
        const contenido = (
          <>
            <IconoRed red={r.red} className="size-3.5" />
            <span className="sr-only">{ICONOS[r.red].nombre}:</span>
            {r.seguidores != null ? compacto(r.seguidores) : 'Síguenos'}
          </>
        )
        const clase = 'flex items-center gap-1.5 rounded-full bg-asfalto-800/90 px-3 py-1 text-xs font-semibold text-stone-200 tabular-nums'

        return (
          <li key={r.red}>
            {r.url ? (
              <a href={r.url} target="_blank" rel="noopener" className={`${clase} hover:bg-asfalto-700 hover:text-linea`}>{contenido}</a>
            ) : (
              <span className={clase}>{contenido}</span>
            )}
          </li>
        )
      })}
    </ul>
  )
}

/** Íconos con enlace a cada red (pie de página). */
export function EnlacesRedes({ redes }: { redes: Red[] }) {
  return (
    <ul className="flex gap-2">
      {redes
        .filter((r) => r.url)
        .map((r) => (
          <li key={r.red}>
            <a
              href={r.url!}
              target="_blank"
              rel="noopener"
              aria-label={ICONOS[r.red].nombre}
              title={ICONOS[r.red].nombre}
              className="grid size-9 place-items-center rounded-full bg-asfalto-800 text-stone-300 hover:bg-asfalto-700 hover:text-linea"
            >
              <IconoRed red={r.red} />
            </a>
          </li>
        ))}
    </ul>
  )
}
