/**
 * Utilidades centralizadas para resolución de medios, carátulas y streaming en FlixHN
 */

export const DEFAULT_FALLBACK_POSTER = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500';

/**
 * Resuelve y normaliza la URL completa de carátulas/pósters.
 * - Si es una ruta relativa de storage (/storage/... o storage/...), antepone la URL base correcta.
 * - Si no existe o es inválida, retorna el fallback garantizado.
 *
 * @param {string|null} url URL cruda de póster o backdrop
 * @returns {string} URL lista para usar en elementos <img>
 */
export const resolvePosterUrl = (url) => {
  if (!url || typeof url !== 'string') {
    return DEFAULT_FALLBACK_POSTER;
  }

  const trimmed = url.trim();
  if (
    !trimmed ||
    trimmed === 'null' ||
    trimmed === 'undefined' ||
    trimmed === '[object Object]'
  ) {
    return DEFAULT_FALLBACK_POSTER;
  }

  // 1. Si ya es una URL absoluta HTTP o HTTPS (TMDb, Emby remoto, CDNs externos)
  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  // 2. Si es una ruta servida desde Laravel storage (/storage/... o storage/...)
  if (trimmed.startsWith('/storage/') || trimmed.startsWith('storage/')) {
    const cleanStoragePath = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;

    // Obtener la URL base del backend desde VITE_API_URL si es absoluta
    const apiUrl = import.meta.env.VITE_API_URL || '';
    if (apiUrl.startsWith('http://') || apiUrl.startsWith('https://')) {
      try {
        const parsed = new URL(apiUrl);
        return `${parsed.origin}${cleanStoragePath}`;
      } catch (e) {}
    }

    // Si estamos en entorno de desarrollo Vite (ej. puerto 5173 u otro)
    if (typeof window !== 'undefined' && window.location.port === '5173') {
      const streamUrl = import.meta.env.VITE_STREAM_URL || '';
      if (streamUrl.startsWith('http')) {
        try {
          const parsed = new URL(streamUrl);
          return `${parsed.protocol}//${parsed.hostname}${cleanStoragePath}`;
        } catch (e) {}
      }
      return `${window.location.protocol}//${window.location.hostname}${cleanStoragePath}`;
    }

    return cleanStoragePath;
  }

  // 3. Si es un asset estático interno de la aplicación (/img/..., /placeholder-..., etc.)
  if (
    trimmed.startsWith('/img/') ||
    trimmed.startsWith('/placeholder') ||
    trimmed.startsWith('/favicon')
  ) {
    return trimmed;
  }

  return trimmed;
};

/**
 * Manejador onError reactivo para imágenes de carátulas.
 * Asigna inmediatamente el fallback garantizado sin provocar ciclos de recarga infinitos.
 *
 * @param {SyntheticEvent} e Evento de error del elemento <img>
 * @param {string} fallback Ruta del fallback institucional
 */
export const handlePosterError = (e, fallback = DEFAULT_FALLBACK_POSTER) => {
  if (!e || !e.target) return;
  e.target.onerror = null;
  e.target.src = fallback;
};
