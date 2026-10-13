import { useState } from 'react'
import type { Fuente, Nivel, TipoDocumento } from '../api'

export const NIVEL: Record<Nivel, { etiqueta: string; clase: string }> = {
  // Con el tema señalética, placas: municipal amarilla, estatal negra, federal blanca con borde.
  municipal: { etiqueta: 'Municipal', clase: 'bg-marca-50 text-marca-800 ring-marca-600/20 senal:bg-senal-acento senal:text-senal-oscuro senal:ring-senal-oscuro/15' },
  estatal: { etiqueta: 'Estatal', clase: 'bg-sky-50 text-sky-800 ring-sky-600/20 senal:bg-senal-oscuro senal:text-white senal:ring-senal-oscuro' },
  federal: { etiqueta: 'Federal', clase: 'bg-violet-50 text-violet-800 ring-violet-600/20 senal:bg-white senal:text-senal-oscuro senal:ring-senal-oscuro' },
}

/** Etiqueta extra solo para lo que no es norma (los reglamentos y leyes no la necesitan). */
const TIPO: Partial<Record<TipoDocumento, string>> = {
  ley: 'Ley',
  manual: 'Manual',
  otro: 'Documento',
}

/** Apartados de un mismo artículo, en el orden en que la búsqueda los encontró. */
interface GrupoArticulo {
  clave: string
  reglamento: string
  tipo: TipoDocumento
  nivel: Nivel
  articulo: string | null
  capitulo: string | null
  seccion: string | null
  contexto: string | null
  apartados: Fuente[]
}

/** Artículos en los que se basa la respuesta, con solo los apartados que responden. */
export function Fuentes({ fuentes, abrirPrimera = false }: { fuentes: Fuente[]; abrirPrimera?: boolean }) {
  return (
    <div className="space-y-2">
      {agrupar(fuentes).map((grupo, i) => (
        <TarjetaArticulo key={grupo.clave} grupo={grupo} abiertaInicial={abrirPrimera && i === 0} />
      ))}
    </div>
  )
}

function agrupar(fuentes: Fuente[]): GrupoArticulo[] {
  const grupos = new Map<string, GrupoArticulo>()

  for (const f of fuentes) {
    // Normas: se agrupa por artículo. Manuales y guías: por su sección.
    const clave = f.articulo
      ? `${f.documento_id}-art-${f.articulo}`
      : `${f.documento_id}-sec-${f.capitulo ?? ''}-${f.seccion ?? ''}`
    const grupo = grupos.get(clave) ?? {
      clave,
      reglamento: f.reglamento,
      tipo: f.tipo,
      nivel: f.nivel,
      articulo: f.articulo,
      capitulo: f.capitulo,
      seccion: f.seccion,
      contexto: f.contexto,
      apartados: [],
    }
    grupo.apartados.push(f)
    grupo.contexto ??= f.contexto
    grupos.set(clave, grupo)
  }

  return [...grupos.values()]
}

function TarjetaArticulo({ grupo, abiertaInicial }: { grupo: GrupoArticulo; abiertaInicial: boolean }) {
  const [abierta, setAbierta] = useState(abiertaInicial)
  const nivel = NIVEL[grupo.nivel]
  const apartados = grupo.apartados.map(nombreApartado).filter(Boolean)

  return (
    <div className="overflow-hidden rounded-xl border border-stone-200 bg-white senal:rounded-2xl senal:border-senal-borde">
      <button
        type="button"
        onClick={() => setAbierta(!abierta)}
        aria-expanded={abierta}
        className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-stone-50"
      >
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-stone-900 senal:font-titulos senal:text-lg senal:font-extrabold senal:uppercase senal:leading-none senal:text-senal-oscuro">
              {grupo.articulo
                ? `Artículo ${grupo.articulo}`
                : capitalizar(grupo.seccion ?? grupo.capitulo ?? 'Fragmento')}
            </span>
            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset senal:rounded-md senal:font-bold senal:uppercase senal:tracking-wide ${nivel.clase}`}>
              {nivel.etiqueta}
            </span>
            {TIPO[grupo.tipo] && (
              <span
                className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20"
                title={grupo.tipo === 'ley' ? undefined : 'Material de estudio, no es una norma obligatoria'}
              >
                {TIPO[grupo.tipo]}
              </span>
            )}
          </span>
          {apartados.length > 0 && (
            <span className="mt-0.5 block text-sm text-stone-600">{apartados.join(' · ')}</span>
          )}
          <span className="mt-0.5 block truncate text-sm text-stone-500" title={grupo.reglamento}>
            {capitalizar(grupo.reglamento)}
          </span>
        </span>
        <svg
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
          className={`mt-1 size-5 shrink-0 text-stone-400 transition-transform ${abierta ? 'rotate-180' : ''}`}
        >
          <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
        </svg>
      </button>

      {abierta && (
        <div className="space-y-3 border-t border-stone-100 bg-stone-50/60 px-4 py-3 senal:border-senal-borde senal:bg-senal-fondo/60">
          {/* En un manual el título de la tarjeta ya es la sección; aquí va el tema que la contiene. */}
          {grupo.capitulo && (grupo.articulo || grupo.seccion) && (
            <p className="text-xs font-medium uppercase tracking-wide text-stone-500">{capitalizar(grupo.capitulo)}</p>
          )}
          {/* Si el proemio del artículo ya viene citado como apartado, no se repite como encabezado. */}
          {grupo.contexto && !grupo.apartados.some((a) => !a.fraccion) && (
            <p className="whitespace-pre-line text-sm leading-relaxed text-stone-500">{grupo.contexto}</p>
          )}
          {ordenar(grupo.apartados).map((a) => (
            <p
              key={a.orden}
              className={`whitespace-pre-line text-sm leading-relaxed text-stone-800 ${grupo.contexto ? 'border-l-2 border-marca-600/40 pl-3 senal:border-l-4 senal:border-senal-acento' : ''}`}
            >
              {a.texto}
            </p>
          ))}
        </div>
      )}
    </div>
  )
}

function nombreApartado(f: Fuente): string | null {
  if (!f.fraccion) return null
  if (f.fraccion.startsWith('parte')) return f.fraccion
  return f.inciso ? `fracc. ${f.fraccion}, inciso ${f.inciso})` : `fracc. ${f.fraccion}`
}

/** Dentro de un artículo o sección se leen en el orden del documento, no por relevancia. */
function ordenar(apartados: Fuente[]): Fuente[] {
  return [...apartados].sort((a, b) => a.orden - b.orden)
}

const PALABRAS_MENORES = new Set([
  'a', 'al', 'con', 'de', 'del', 'e', 'el', 'en', 'la', 'las', 'lo', 'los', 'o', 'para', 'por', 'que', 'se', 'sin', 'su', 'sus', 'u', 'un', 'una', 'y',
])

// Tras estas palabras una letra suelta es una categoría ("tipo A", "clase B"), no una preposición.
const ANTES_DE_CATEGORIA = new Set(['tipo', 'clase', 'categoría', 'inciso', 'grupo'])

/**
 * Los PDF oficiales suelen venir en MAYÚSCULAS. Se pasan a tipo título en
 * español ("Reglamento de la Ley de Movilidad para el Estado de Oaxaca"):
 * así los nombres propios conservan su mayúscula y los artículos y
 * preposiciones quedan en minúscula. Los números romanos ("III.-") y las
 * categorías ("tipo A", "tipo "F"") se dejan tal cual.
 */
function capitalizar(texto: string): string {
  if (texto !== texto.toUpperCase()) return texto

  const palabras = texto.split(' ')
  let primera = true // la primera palabra del título va con mayúscula aunque sea "para"

  return palabras
    .map((original, i) => {
      const anterior = (palabras[i - 1] ?? '').toLocaleLowerCase('es-MX')

      // "II.-SEÑALES" → "II.-" se conserva y "SEÑALES" sigue como palabra normal.
      const romano = /^([IVXLC]+[.\-–)]+)(.*)$/.exec(original)
      if (romano) {
        if (!romano[2]) return romano[1]
        original = romano[2]
      }
      if (ANTES_DE_CATEGORIA.has(anterior) && /^["“”«]?\p{Lu}["“”»]?$/u.test(original)) return original

      const palabra = original.toLocaleLowerCase('es-MX')
      const resultado = !primera && PALABRAS_MENORES.has(palabra)
        ? palabra
        : palabra.charAt(0).toLocaleUpperCase('es-MX') + palabra.slice(1)
      primera = false

      return (romano?.[1] ?? '') + resultado
    })
    .join(' ')
}

/** "Consulté: [Municipal · Oaxaca de Juárez] [Estatal · Oaxaca]": qué reglamentos cubre la respuesta. */
export function Ambitos({ ambitos, inicio = 'Consulté' }: { ambitos: { nivel: Nivel; nombre: string }[]; inicio?: string }) {
  if (ambitos.length === 0) return null

  return (
    <p className="flex items-start gap-1.5 text-xs leading-relaxed text-stone-500">
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="mt-0.5 size-3.5 shrink-0 senal:text-senal-oscuro">
        <path d="M10.75 16.82A7.462 7.462 0 0 1 15 15.5c.71 0 1.396.098 2.046.282A.75.75 0 0 0 18 15.06v-11a.75.75 0 0 0-.546-.721A9.006 9.006 0 0 0 15 3a8.963 8.963 0 0 0-4.25 1.065V16.82ZM9.25 4.065A8.963 8.963 0 0 0 5 3c-.85 0-1.673.118-2.454.339A.75.75 0 0 0 2 4.06v11a.75.75 0 0 0 .954.721A7.506 7.506 0 0 1 5 15.5c1.579 0 3.042.487 4.25 1.32V4.065Z" />
      </svg>
      <span>
        {inicio} {fraseAmbitos(ambitos)}.
      </span>
    </p>
  )
}

/**
 * "el reglamento municipal de Oaxaca de Juárez y el estatal de Oaxaca". Una
 * frase se acomoda sola en cualquier ancho; las placas se partían en dos renglones.
 */
export function fraseAmbitos(ambitos: { nivel: Nivel; nombre: string }[]): string {
  const partes = ambitos.map((a, i) =>
    a.nivel === 'federal' ? 'la normativa federal' : `${i === 0 ? 'el reglamento' : 'el'} ${a.nivel} de ${a.nombre}`,
  )
  return new Intl.ListFormat('es', { type: 'conjunction' }).format(partes)
}

/** Placa del nivel de un reglamento (municipal, estatal o federal), con el lugar si se da. */
export function PlacaNivel({ nivel, lugar }: { nivel: Nivel; lugar?: string }) {
  const { etiqueta, clase } = NIVEL[nivel]
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset senal:rounded-md senal:font-bold senal:tracking-wide ${clase}`}>
      <span className="senal:uppercase">{etiqueta}</span>
      {lugar && <span className="font-normal opacity-80 senal:font-semibold senal:opacity-90">· {lugar}</span>}
    </span>
  )
}
