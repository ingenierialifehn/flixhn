import './fs-shim.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@api': path.resolve(__dirname, './src/api'),
      '@services': path.resolve(__dirname, './src/services'),
    },
    extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json'],
  },
  plugins: [
    react(),
    {
      name: 'suppress-watcher-eio',
      configureServer(server) {
        server.watcher.on('error', (err) => {
          if (err?.code === 'EIO') {
            return; // Manejo silencioso de errores temporales de I/O en Docker para Windows
          }
          console.error('Vite Watcher Error:', err);
        });
      },
    },
  ],
  cacheDir: '/tmp/.vite',
  optimizeDeps: {
    noDiscovery: true,
    include: ['react', 'react-dom', 'react-dom/client', 'axios', 'hls.js', 'lucide-react'],
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    watch: {
      usePolling: true,
      interval: 1000,
      ignored: ['**/node_modules/**', '**/dist/**', '**/.git/**', '/tmp/**'],
    },
  },
});
