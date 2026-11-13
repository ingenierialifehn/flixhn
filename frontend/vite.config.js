import './fs-shim.js';
import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appRoot = fs.existsSync('/app/index.html') ? '/app' : __dirname;

export default defineConfig({
  root: appRoot,
  resolve: {
    dedupe: ['react', 'react-dom', 'react/jsx-runtime', 'react/jsx-dev-runtime'],
    alias: {
      '@': path.resolve(appRoot, './src'),
      '@api': path.resolve(appRoot, './src/api'),
      '@services': path.resolve(appRoot, './src/services'),
    },
    extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json'],
  },
  plugins: [
    react(),
    {
      name: 'suppress-watcher-eio',
      configureServer(server) {
        server.watcher.on('error', (err) => {
          if (err?.code === 'EIO') return;
          console.error('Vite Watcher Error:', err);
        });
      },
    },
  ],
  cacheDir: fs.existsSync('/tmp') ? '/tmp/.vite' : undefined,
  optimizeDeps: {
    force: true,
    include: ['react', 'react-dom', 'react/jsx-dev-runtime', 'hls.js'],
    exclude: ['mpegts.js'],
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    watch: {
      usePolling: true,
      interval: 1000,
      ignored: ['**/node_modules/**', '**/dist/**', '**/.git/**', '/tmp/**', '**/.vite/**', '**/*.timestamp-*'],
    },
  },
});
