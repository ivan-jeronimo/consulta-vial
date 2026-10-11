import { useLayoutEffect, useRef, useState } from 'react'

const MAXIMO = 1000

interface Props {
  deshabilitado: boolean
  onEnviar: (pregunta: string) => void
}

export function CampoPregunta({ deshabilitado, onEnviar }: Props) {
  const [texto, setTexto] = useState('')
  const area = useRef<HTMLTextAreaElement>(null)
  const valido = texto.trim().length >= 5 && texto.length <= MAXIMO

  // Crece con el contenido hasta ~6 líneas.
  useLayoutEffect(() => {
    const el = area.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`
  }, [texto])

  function enviar() {
    if (!valido || deshabilitado) return
    onEnviar(texto.trim())
    setTexto('')
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        enviar()
      }}
      className="flex items-end gap-2 rounded-2xl border border-stone-300 bg-white p-2 shadow-sm focus-within:border-marca-600 focus-within:ring-2 focus-within:ring-marca-600/20 senal:border-2 senal:border-senal-oscuro senal:shadow-none senal:focus-within:border-senal-oscuro senal:focus-within:ring-4 senal:focus-within:ring-senal-acento/50"
    >
      <label htmlFor="pregunta" className="sr-only">Tu pregunta</label>
      <textarea
        id="pregunta"
        ref={area}
        rows={1}
        value={texto}
        maxLength={MAXIMO}
        onChange={(e) => setTexto(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault()
            enviar()
          }
        }}
        placeholder="Escribe tu pregunta sobre el reglamento…"
        className="max-h-40 flex-1 resize-none bg-transparent px-2 py-2 text-base placeholder:text-stone-400 focus:outline-none"
      />
      <button
        type="submit"
        disabled={!valido || deshabilitado}
        aria-label="Enviar pregunta"
        className="grid size-10 shrink-0 place-items-center rounded-xl bg-marca-700 text-white transition hover:bg-marca-800 disabled:bg-stone-200 disabled:text-stone-400 senal:bg-senal-acento senal:text-senal-oscuro senal:hover:bg-senal-acento/80"
      >
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="size-5">
          <path d="M3.105 2.288a.75.75 0 0 0-.826.95l1.414 4.926A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.897 28.897 0 0 0 15.293-7.155.75.75 0 0 0 0-1.114A28.897 28.897 0 0 0 3.105 2.288Z" />
        </svg>
      </button>
    </form>
  )
}
