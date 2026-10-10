/**
 * Modo incrustado: otro sitio abre el chat dentro de un iframe, p. ej.
 *   /?incrustado=1&marca=Asistente%20vial&color=27272a&origen=andantes&estado=20
 *
 * - marca:   título corto del encabezado (sin logo de Consulta Vial).
 * - color:   color principal en hex, sin #. Botones y acentos lo usan.
 * - origen:  identificador del sitio, para las métricas del panel.
 * - estado:  id del estado con el que arranca si la persona aún no elige ubicación.
 *
 * Abajo queda la línea "Con tecnología de Consulta Vial".
 */
const parametros = new URLSearchParams(location.search)

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
