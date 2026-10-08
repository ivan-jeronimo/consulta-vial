const numeroCompacto = new Intl.NumberFormat('es-MX', { notation: 'compact', maximumFractionDigits: 1 })
const fechaCorta = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', timeZone: 'UTC' })
const fechaLarga = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })

/** 80500 → "80.5 mil" */
export const compacto = (n: number) => numeroCompacto.format(n)

/** Fechas "AAAA-MM-DD" de la API; se leen en UTC para que no se recorran un día. */
export const fecha = (iso: string, conAnio = false) => (conAnio ? fechaLarga : fechaCorta).format(new Date(iso))

export const iniciales = (nombre: string) =>
  nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('')
