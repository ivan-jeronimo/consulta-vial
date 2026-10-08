import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const laravel = env.VITE_API_PROXY ?? 'http://localhost:8000'

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5174,
      strictPort: true,
      // En Docker sobre Windows los cambios no llegan como eventos del sistema
      // de archivos: se revisan cada cierto tiempo para la recarga en vivo.
      watch: env.VITE_USAR_POLLING ? { usePolling: true, interval: 300 } : undefined,
      // /api y las imágenes subidas desde el panel (/storage) vienen de Laravel.
      proxy: {
        '/api': laravel,
        '/storage': laravel,
      },
    },
  }
})
