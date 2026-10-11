/**
 * Modo incrustado: otro sitio abre el chat dentro de un iframe, p. ej.
 *   /?incrustado=1&marca=Asistente%20vial&color=27272a&origen=andantes&estado=20
 *
 * - marca:   título corto del encabezado (sin logo de Consulta Vial).
 * - color:   color principal en hex, sin #. Botones y acentos lo usan.
 * - origen:  identificador del sitio, para las métricas del panel.
 * - estado:  id del estado con el que arranca si la persona aún no elige ubicación.
 * - fondo:   color de fondo en hex, sin # (el del sitio que incrusta).
 * - fuente:  tipografía del sitio, de una lista cerrada (FUENTES); los títulos usan su versión condensada.
 */
const parametros = new URLSearchParams(location.search)

/** Mientras sea true, el chat avisa que es una versión de prueba y puede equivocarse. */
export const EN_PRUEBA = true

/**
 * Antes de publicar Consulta Vial por su cuenta: con VITE_SOLO_INCRUSTADO (una
 * URL), quien abra el chat directamente se va ahí; solo funciona incrustado.
 */
export const soloIncrustado: string = import.meta.env.VITE_SOLO_INCRUSTADO ?? ''

export const incrustado = parametros.get('incrustado') === '1'

export const marca = incrustado ? (parametros.get('marca')?.trim().slice(0, 40) || 'Asistente vial') : null

const valor = (nombre: string, patron: RegExp) => {
  const v = parametros.get(nombre) ?? ''
  return incrustado && patron.test(v) ? v : null
}

export const origen = valor('origen', /^[\w-]{1,40}$/)

export const estadoInicial = Number(valor('estado', /^\d{1,4}$/)) || null

/** Cambia el color de la marca (variables de Tailwind --color-marca-*) por el del sitio que incrusta. */
export function aplicarColor() {
  const color = valor('color', /^[0-9a-f]{6}$/i)
  if (!color) return

  const raiz = document.documentElement.style
  const hex = `#${color}`
  raiz.setProperty('--color-marca-600', hex)
  raiz.setProperty('--color-marca-700', hex)
  raiz.setProperty('--color-marca-800', `color-mix(in srgb, ${hex} 80%, black)`)
  raiz.setProperty('--color-marca-50', `color-mix(in srgb, ${hex} 6%, white)`)
  raiz.setProperty('--color-marca-100', `color-mix(in srgb, ${hex} 14%, white)`)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', hex)
}

/** Fuentes permitidas (Google Fonts): texto y títulos. Una lista cerrada: nada de URLs del sitio que incrusta. */
const FUENTES: Record<string, { css: string; texto: string; titulos: string }> = {
  barlow: {
    css: 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Barlow:wght@400;500;600;700&display=swap',
    texto: "'Barlow', ui-sans-serif, system-ui, sans-serif",
    titulos: "'Barlow Condensed', 'Barlow', ui-sans-serif, system-ui, sans-serif",
  },
}

/** Fondo y tipografía del sitio que incrusta, para que el chat se vea como parte de él. */
export function aplicarEstiloDelSitio() {
  const fondo = valor('fondo', /^[0-9a-f]{6}$/i)
  // El fondo de la página y el degradado sobre el campo de pregunta usan stone-50.
  if (fondo) document.documentElement.style.setProperty('--color-stone-50', `#${fondo}`)

  const fuente = FUENTES[valor('fuente', /^[a-z]{1,20}$/) ?? '']
  if (!fuente) return

  const enlace = document.createElement('link')
  enlace.rel = 'stylesheet'
  enlace.href = fuente.css
  document.head.append(enlace)

  const estilo = document.createElement('style')
  estilo.textContent = `
    :root { --font-sans: ${fuente.texto}; }
    h1 { font-family: ${fuente.titulos}; font-weight: 800; text-transform: uppercase; line-height: 0.95; letter-spacing: 0; }
  `
  document.head.append(estilo)
}
