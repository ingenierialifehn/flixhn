/**
 * FlixHN OTT Dynamic Service
 * Cliente API oficial para la Integración y Reproductor OTT Dinámico.
 */
import api from '../api/axios';

export const ottService = {
  /**
   * Realiza una búsqueda unificada en catálogo local y plataformas OTT autorizadas.
   *
   * @param {string} query Término de búsqueda
   * @param {object} options Filtros: { type: 'all'|'movie'|'series', provider: 'all'|string, limit: number }
   * @param {AbortSignal} [signal] Controlador de cancelación de petición en vuelo
   */
  async search(query, options = {}, signal = null) {
    const payload = {
      q: query,
      type: options.type || 'all',
      provider: options.provider || 'all',
      limit: options.limit || 30,
    };
    const res = await api.post('/ott/search', payload, { signal });
    return res.data;
  },

  /**
   * Obtiene la información de reproducción oficial y autorizada para un contenido.
   *
   * @param {object} params { content_id: string, provider?: string, type?: string }
   * @param {AbortSignal} [signal]
   */
  async getStreamLink(params, signal = null) {
    const res = await api.post('/ott/stream-link', params, { signal });
    return res.data;
  },

  /**
   * Lista todos los proveedores OTT configurados y su estado sin comprometer secretos.
   */
  async getProviders() {
    const res = await api.get('/ott/providers');
    return res.data;
  },

  /**
   * Verifica la salud y conectividad de un proveedor.
   *
   * @param {string|number} providerId Slug o ID del proveedor
   */
  async getProviderStatus(providerId) {
    const res = await api.get(`/ott/provider/${encodeURIComponent(providerId)}/status`);
    return res.data;
  },
};

export default ottService;
