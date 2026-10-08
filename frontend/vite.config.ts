import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      strictPort: true,
      // En Docker sobre Windows los cambios no llegan como eventos del sistema
      // de archivos: se revisan cada cierto tiempo para la recarga en vivo.
      watch: env.VITE_USAR_POLLING ? { usePolling: true, interval: 300 } : undefined,
      // En desarrollo /api se reenvía a Laravel: mismo origen, sin CORS.
      proxy: {
        '/api': env.VITE_API_PROXY ?? 'http://localhost:8000',
      },
    },
  }
})
