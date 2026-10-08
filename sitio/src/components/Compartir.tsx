import { useState } from 'react'
import { IconoRed } from './Redes'

/**
 * Compartir el resultado. En el celular abre el menú nativo (WhatsApp,
 * Facebook, Instagram…); siempre hay accesos directos y "copiar enlace".
 * La vista previa (imagen con el puntaje) la arma el enlace /r/{partida}.
 */
export function CompartirResultado({ url, puntos, aciertos, total }: { url: string; puntos: number; aciertos: number; total: number }) {
  const [copiado, setCopiado] = useState(false)
  const texto = `Hice ${puntos.toLocaleString('es-MX')} puntos (${aciertos} de ${total}) en El reto del asfalto 🏍️ ¿Me superas?`
  const nativo = typeof navigator.share === 'function'

  const compartir = async () => {
    try {
      await navigator.share({ title: 'El reto del asfalto', text: texto, url })
    } catch {
      // Cerró el menú sin compartir: no es un error.
    }
  }

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(`${texto} ${url}`)
      setCopiado(true)
      setTimeout(() => setCopiado(false), 2500)
    } catch {
      prompt('Copia este enlace:', url)
    }
  }

  const enlace = 'grid size-11 place-items-center rounded-full text-white shadow transition hover:scale-105'

  return (
    <div className="mx-auto mt-6 max-w-sm rounded-2xl bg-white p-4 ring-1 ring-stone-200">
      <p className="text-sm font-bold">Reta a tus amigos</p>
      {nativo && (
        <button
          type="button"
          onClick={compartir}
          className="mt-3 flex w-full items-center justify-center gap-2 rounded-full bg-asfalto-900 px-5 py-3 font-bold text-white hover:bg-asfalto-700"
        >
          <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7M16 6l-4-4-4 4M12 2v13" />
          </svg>
          Compartir mi resultado
        </button>
      )}
      <div className="mt-3 flex items-center justify-center gap-3">
        <a
          href={`https://wa.me/?text=${encodeURIComponent(`${texto} ${url}`)}`}
          target="_blank"
          rel="noopener"
          aria-label="Compartir en WhatsApp"
          title="WhatsApp"
          className={`${enlace} bg-[#25D366]`}
        >
          <svg viewBox="0 0 24 24" className="size-5" fill="currentColor" aria-hidden>
            <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88m8.41-18.29A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.31-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.16-3.49-8.41" />
          </svg>
        </a>
        <a
          href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`}
          target="_blank"
          rel="noopener"
          aria-label="Compartir en Facebook"
          title="Facebook"
          className={`${enlace} bg-[#0866FF]`}
        >
          <IconoRed red="facebook" className="size-5" />
        </a>
        <button type="button" onClick={copiar} aria-label="Copiar enlace" title="Copiar enlace" className={`${enlace} bg-stone-600`}>
          {copiado ? (
            <span className="text-lg font-black">✓</span>
          ) : (
            <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            </svg>
          )}
        </button>
      </div>
      {copiado && <p className="mt-2 text-center text-xs text-green-700">¡Enlace copiado!</p>}
    </div>
  )
}
