import api from '../api/axios';

const DEVICE_ID_KEY = 'flixhn_device_id';

/**
 * Genera un identificador UUID v4 seguro o utiliza fallback manual
 * para contextos HTTP sin cifrado (ej. http://65.187.110.22) donde crypto.randomUUID no está disponible.
 */
export const generateUUID = () => {
  if (typeof crypto !== 'undefined' && typeof crypto?.randomUUID === 'function') {
    try {
      return crypto.randomUUID();
    } catch (e) {
      // Ignorar fallo y recurrir al fallback
    }
  }

  // Fallback manual compatible con navegadores en HTTP
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
};

/**
 * Genera o recupera el identificador persistente único del dispositivo cliente.
 */
export const getPersistentDeviceId = () => {
  try {
    let id = typeof localStorage !== 'undefined' ? localStorage.getItem(DEVICE_ID_KEY) : null;
    if (!id) {
      id = 'dev-' + generateUUID();
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem(DEVICE_ID_KEY, id);
      }
    }
    return id;
  } catch (e) {
    return 'dev-browser-' + Date.now();
  }
};

/**
 * Singleton Map en memoria para gestionar las sesiones activas de telemetría y sus temporizadores.
 * Al no depender de useRef ni useEffect de React, elimina completamente cualquier riesgo de
 * colisión o desincronización en ReactCurrentDispatcher durante Hot Module Replacement (HMR).
 */
const activeSessions = new Map();

/**
 * Envío de señal de detención al backend (/api/telemetry/stop).
 */
const sendStopSignal = (deviceId) => {
  const token =
    typeof localStorage !== 'undefined'
      ? localStorage.getItem('flixhn_token') || localStorage.getItem('token')
      : null;

  const payload = { device_id: deviceId };
  const payloadStr = JSON.stringify(payload);
  const baseUrl = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '');
  const url = `${baseUrl}/telemetry/stop`;

  // Envío prioritario vía Axios
  try {
    api.post('/telemetry/stop', payload).catch(() => {});
  } catch (e) {}

  // Envío con keepalive para eventos de cierre de ventana / navegación
  if (typeof fetch !== 'undefined') {
    try {
      fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: payloadStr,
        keepalive: true,
      }).catch(() => {});
    } catch (e) {}
  } else if (typeof navigator !== 'undefined' && navigator.sendBeacon) {
    try {
      const blob = new Blob([payloadStr], { type: 'application/json' });
      navigator.sendBeacon(url, blob);
    } catch (e) {}
  }
};

// Listeners globales para limpieza ante cierre o recarga de la pestaña
if (typeof window !== 'undefined' && !window.__flixhn_telemetry_bound) {
  window.__flixhn_telemetry_bound = true;
  const handleGlobalUnload = () => {
    for (const [key, session] of activeSessions.entries()) {
      if (session.intervalId) {
        clearInterval(session.intervalId);
      }
      sendStopSignal(session.deviceId);
      activeSessions.delete(key);
    }
  };

  window.addEventListener('beforeunload', handleGlobalUnload);
  window.addEventListener('pagehide', handleGlobalUnload);
}

/**
 * Hook / Controlador de Telemetría Real de FlixHN para Reproductores (VOD y LiveTV).
 * Gestiona el ciclo de vida del latido de 15s mediante `activeSessions` sin invocar hooks de React.
 */
export function useTelemetryHeartbeat({
  mediaType = 'vod',
  mediaId,
  channelId,
  title,
  poster,
  currentTime = 0,
  duration = 0,
  playbackState = 'playing',
  onRemoteCommand,
  onBlocked,
  enabled = true,
} = {}) {
  const deviceId = getPersistentDeviceId();
  const effectiveId = channelId ?? mediaId ?? null;
  const sessionKey = `${mediaType}_${effectiveId || 'active'}`;

  // Enviar latido de telemetría con parámetros actualizados
  const sendHeartbeat = async () => {
    const session = activeSessions.get(sessionKey);
    const p = session?.props || {
      mediaType,
      mediaId: effectiveId,
      channelId: effectiveId,
      title,
      poster,
      currentTime,
      duration,
      playbackState,
      onRemoteCommand,
      onBlocked,
      enabled,
      deviceId,
    };

    if (!p.enabled || !p.title || p.playbackState === 'stopped') {
      return;
    }

    const currentId = p.channelId ?? p.mediaId;
    if (p.mediaType === 'livetv' && !currentId) {
      return;
    }

    const currentSecs = typeof p.currentTime === 'function' ? p.currentTime() : p.currentTime;
    const totalSecs = typeof p.duration === 'function' ? p.duration() : p.duration;

    const payload = {
      device_id: p.deviceId || deviceId,
      media_type: p.mediaType,
      media_id: p.mediaId || null,
      title: p.title,
      poster: p.poster || null,
      current_time: Math.floor(currentSecs || 0),
      duration: Math.floor(totalSecs || 0),
      playback_state: p.playbackState === 'paused' ? 'paused' : 'playing',
      screen_resolution:
        typeof window !== 'undefined'
          ? `${window.screen?.width || window.innerWidth}x${window.screen?.height || window.innerHeight}`
          : undefined,
    };

    try {
      const res = await api.post('/telemetry/heartbeat', payload);
      if (res.data) {
        if (res.data.is_blocked && typeof p.onBlocked === 'function') {
          p.onBlocked();
        }
        if (res.data.remote_command && typeof p.onRemoteCommand === 'function') {
          p.onRemoteCommand(res.data.remote_command);
        }
      }
    } catch (err) {
      // Ignorar fallas momentáneas de red de manera silenciosa
    }
  };

  const sendStop = () => {
    if (activeSessions.has(sessionKey)) {
      const session = activeSessions.get(sessionKey);
      if (session.intervalId) {
        clearInterval(session.intervalId);
      }
      activeSessions.delete(sessionKey);
    }
    sendStopSignal(deviceId);
  };

  // Limpiar cualquier otra sesión huérfana de este mismo mediaType
  for (const [key, session] of activeSessions.entries()) {
    if (key !== sessionKey && key.startsWith(mediaType + '_')) {
      if (session.intervalId) {
        clearInterval(session.intervalId);
      }
      sendStopSignal(session.deviceId || deviceId);
      activeSessions.delete(key);
    }
  }

  const shouldBeActive =
    Boolean(enabled) &&
    Boolean(title) &&
    playbackState !== 'stopped' &&
    (mediaType !== 'livetv' || Boolean(effectiveId));

  if (!shouldBeActive) {
    if (activeSessions.has(sessionKey)) {
      const session = activeSessions.get(sessionKey);
      if (session.intervalId) {
        clearInterval(session.intervalId);
      }
      activeSessions.delete(sessionKey);
      sendStopSignal(deviceId);
    }
  } else {
    const currentProps = {
      mediaType,
      mediaId: effectiveId,
      channelId: effectiveId,
      title,
      poster,
      currentTime,
      duration,
      playbackState,
      onRemoteCommand,
      onBlocked,
      enabled,
      deviceId,
    };

    if (activeSessions.has(sessionKey)) {
      const session = activeSessions.get(sessionKey);
      session.props = currentProps;
    } else {
      // Crear nueva sesión con intervalo recurrente de 15s
      const intervalId = setInterval(() => {
        sendHeartbeat();
      }, 15000);

      activeSessions.set(sessionKey, {
        props: currentProps,
        intervalId,
        deviceId,
        sendHeartbeat,
      });

      // Primer latido inmediato
      sendHeartbeat();
    }
  }

  return { deviceId, sendHeartbeat, sendStop };
}

export default useTelemetryHeartbeat;
