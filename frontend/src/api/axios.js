import axios from 'axios';
import { showFlixHnToast } from '../components/Toast';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Interceptor para inyectar token Sanctum
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('flixhn_token') || localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
}, (error) => {
  return Promise.reject(error);
});

// Interceptor para manejar expiración de sesión (401) y contenido eliminado (404 / 410)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const data = error.response?.data;
    const url = error.config?.url || '';

    // Ignorar completamente cualquier error proveniente de URLs de imágenes (/local-image, /logo, .jpg, .png, .webp, etc.)
    // para que una carátula faltante jamás bloquee ni purgue una película o serie.
    const isImageUrl =
      url.includes('/local-image') ||
      url.includes('/logo') ||
      /\.(jpe?g|png|webp|svg|gif)(\?.*)?$/i.test(url);

    if (isImageUrl) {
      return Promise.reject(error);
    }

    // Manejo de contenido eliminado o no disponible (404 o 410)
    const isTargetEndpoint =
      url.includes('/media') ||
      url.includes('/titles') ||
      url.includes('/episodes') ||
      url.includes('/channels') ||
      url.includes('/livetv') ||
      url.includes('/stream');

    const isDeletedOrMissing =
      status === 410 ||
      (status === 404 && isTargetEndpoint) ||
      data?.deleted === true;

    if (isDeletedOrMissing) {
      // Extraer identificador y tipo de contenido
      let id = data?.id;
      let type = data?.type || 'media';

      if (!id) {
        const idMatch = url.match(/(?:\/media\/|\/titles\/|\/episodes\/|\/channels\/|\/stream\/(?:title\/|episode\/)?)([0-9]+)/);
        if (idMatch) {
          id = parseInt(idMatch[1], 10);
        }
      }

      if (url.includes('/channels') || url.includes('/livetv')) {
        type = 'channel';
      } else if (url.includes('/episodes') || url.includes('/stream/episode/')) {
        type = 'episode';
      } else if (url.includes('/titles') || url.includes('/stream/title/')) {
        type = 'movie';
      }

      // 1. Disparar evento global reactivo para actualización instantánea en la UI
      if (typeof window !== 'undefined') {
        window.dispatchEvent(
          new CustomEvent('media:removed', {
            detail: {
              id,
              type,
              url,
              deleted: true,
              message: data?.message || 'Este contenido fue eliminado y ya no está disponible.',
            },
          })
        );
      }

      // 2. Notificación Toast elegante con estética FlixHN (Sin alerts nativos)
      showFlixHnToast('Este contenido fue eliminado y ya no está disponible', 'error');
    }

    // Manejo de sesión expirada
    if (status === 401) {
      localStorage.removeItem('flixhn_token');
      localStorage.removeItem('token');
      localStorage.removeItem('flixhn_user');
      localStorage.removeItem('flixhn_profile');
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event('flixhn_unauthorized'));
      }
    }

    return Promise.reject(error);
  }
);

export default api;
