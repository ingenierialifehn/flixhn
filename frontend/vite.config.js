import './fs-shim.js';
import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const isContainer = fs.existsSync('/app');
const appRoot = isContainer ? '/app' : __dirname;

export default defineConfig({
  root: appRoot,
  cacheDir: isContainer ? '/app/node_modules/.vite' : path.resolve(__dirname, 'node_modules/.vite'),
  resolve: {
    dedupe: ['react', 'react-dom', 'react/jsx-runtime', 'react/jsx-dev-runtime', 'lucide-react'],
    alias: isContainer
      ? {
          react: '/app/node_modules/react',
          'react-dom': '/app/node_modules/react-dom',
          'react/jsx-runtime': '/app/node_modules/react/jsx-runtime',
          'react/jsx-dev-runtime': '/app/node_modules/react/jsx-dev-runtime',
          '@': '/app/src',
          '@api': '/app/src/api',
          '@services': '/app/src/services',
        }
      : {
          react: path.resolve(__dirname, 'node_modules/react'),
          'react-dom': path.resolve(__dirname, 'node_modules/react-dom'),
          'react/jsx-runtime': path.resolve(__dirname, 'node_modules/react/jsx-runtime'),
          'react/jsx-dev-runtime': path.resolve(__dirname, 'node_modules/react/jsx-dev-runtime'),
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
          if (err?.code === 'EIO') return;
          console.error('Vite Watcher Error:', err);
        });
      },
    },
  ],
  optimizeDeps: {
    force: true,
    include: ['react', 'react-dom', 'react/jsx-runtime', 'react/jsx-dev-runtime', 'lucide-react', 'hls.js'],
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
