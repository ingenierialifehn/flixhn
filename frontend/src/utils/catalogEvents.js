/**
 * Canal global de sincronización de catálogo FlixHN
 * Permite comunicar cambios de catálogo y descargas entre pestañas (BroadcastChannel)
 * y dentro de la misma aplicación SPA (CustomEvent 'catalogUpdated').
 */

const CHANNEL_NAME = 'flixhn_catalog_sync';
const EVENT_NAME = 'catalogUpdated';

/**
 * Emite la señal de que el catálogo o una tarea ha sido actualizada.
 * @param {Object} [payload={}] Datos opcionales del cambio (acción, tarea, etc.)
 */
export const notifyCatalogUpdated = (payload = {}) => {
  if (typeof window === 'undefined') return;

  const eventData = {
    type: 'catalog_updated',
    timestamp: Date.now(),
    ...payload,
  };

  // 1. BroadcastChannel para sincronizar entre diferentes pestañas/ventanas abiertas
  try {
    if (typeof BroadcastChannel !== 'undefined') {
      const channel = new BroadcastChannel(CHANNEL_NAME);
      channel.postMessage(eventData);
      channel.close();
    }
  } catch (err) {
    // Si el navegador bloquea o no soporta BroadcastChannel, continúa silenciosamente
  }

  // 2. CustomEvent en window para módulos dentro de la misma ventana / SPA
  try {
    window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: eventData }));
  } catch (err) {
    // Fallback estándar con Event
    try {
      window.dispatchEvent(new Event(EVENT_NAME));
    } catch (e) {}
  }
};

/**
 * Suscribe un callback para escuchar eventos de sincronización de catálogo.
 * Retorna la función de limpieza (cleanup) para useEffect.
 * 
 * @param {Function} callback Función que se ejecutará al recibir la notificación
 * @returns {Function} Función cleanup para desuscribirse
 */
export const subscribeToCatalogUpdates = (callback) => {
  if (typeof window === 'undefined' || typeof callback !== 'function') {
    return () => {};
  }

  let broadcastChannel = null;

  // 1. Escuchar BroadcastChannel
  try {
    if (typeof BroadcastChannel !== 'undefined') {
      broadcastChannel = new BroadcastChannel(CHANNEL_NAME);
      broadcastChannel.onmessage = (event) => {
        try {
          callback(event?.data || {});
        } catch (e) {
          console.error('Error en listener de BroadcastChannel:', e);
        }
      };
    }
  } catch (err) {
    broadcastChannel = null;
  }

  // 2. Escuchar CustomEvent en window
  const handleCustomEvent = (event) => {
    try {
      callback(event?.detail || {});
    } catch (e) {
      console.error('Error en listener de catalogUpdated:', e);
    }
  };

  window.addEventListener(EVENT_NAME, handleCustomEvent);

  // Cleanup
  return () => {
    if (broadcastChannel) {
      try {
        broadcastChannel.close();
      } catch (e) {}
    }
    window.removeEventListener(EVENT_NAME, handleCustomEvent);
  };
};

export default {
  notifyCatalogUpdated,
  subscribeToCatalogUpdates,
};
