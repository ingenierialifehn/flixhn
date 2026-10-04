/**
 * FlixHN API Client Service
 * Re-exporta la instancia configurada de Axios con interceptores de Sanctum y manejo de sesión.
 */
import api from '../api/axios';

export default api;
export * from '../api/axios';
