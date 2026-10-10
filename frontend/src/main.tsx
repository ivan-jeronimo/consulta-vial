import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import './index.css'
import { aplicarColor, incrustado, soloIncrustado } from './incrustado'

if (soloIncrustado && !incrustado) {
  // Aún no se publica por su cuenta: quien lo abra directo se va al sitio que lo incrusta.
  location.replace(soloIncrustado)
} else {
  if (soloIncrustado) document.head.insertAdjacentHTML('beforeend', '<meta name="robots" content="noindex, nofollow">')
  aplicarColor()

  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
}
