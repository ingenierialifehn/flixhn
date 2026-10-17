import { useEffect, useRef, useCallback } from 'react';
import api from '../api/axios';

const DEVICE_ID_KEY = 'flixhn_device_id';

/**
 * Genera o recupera el identificador persistente único del dispositivo cliente.
 */
export const getPersistentDeviceId = () => {
  try {
    let id = localStorage.getItem(DEVICE_ID_KEY);
    if (!id) {
      if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        id = 'dev-' + crypto.randomUUID();
      } else {
        id = 'dev-' + 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
          const r = (Math.random() * 16) | 0;
          const v = c === 'x' ? r : (r & 0x3) | 0x8;
          return v.toString(16);
        });
      }
      localStorage.setItem(DEVICE_ID_KEY, id);
    }
    return id;
  } catch (e) {
    return 'dev-browser-' + Date.now();
  }
};

/**
 * Hook de Telemetría Real de FlixHN para Reproductores (VOD y LiveTV).
 * Emite heartbeat POST /api/telemetry/heartbeat cada 15s mientras el video esté activo o pausado.
 * Al desmontar o salir, envía POST /api/telemetry/stop inmediatamente.
 */
export default function useTelemetryHeartbeat({
  mediaType = 'vod', // 'vod' | 'livetv'
  mediaId,
  title,
  poster,
  currentTime = 0,
  duration = 0,
  playbackState = 'playing', // 'playing' | 'paused' | 'stopped'
  onRemoteCommand,
  onBlocked,
  enabled = true,
}) {
  const deviceId = getPersistentDeviceId();

  // Mantener los valores más recientes sin reiniciar temporizadores
  const latestProps = useRef({
    mediaType,
    mediaId,
    title,
    poster,
    currentTime,
    duration,
    playbackState,
    onRemoteCommand,
    onBlocked,
    enabled,
  });

  useEffect(() => {
    latestProps.current = {
      mediaType,
      mediaId,
      title,
      poster,
      currentTime,
      duration,
      playbackState,
      onRemoteCommand,
      onBlocked,
      enabled,
    };
  });

  // Función de envío de latido
  const sendHeartbeat = useCallback(async () => {
    const p = latestProps.current;
    if (!p.enabled || !p.title || p.playbackState === 'stopped') {
      return;
    }

    const currentSecs = typeof p.currentTime === 'function' ? p.currentTime() : p.currentTime;
    const totalSecs = typeof p.duration === 'function' ? p.duration() : p.duration;

    const payload = {
      device_id: deviceId,
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
  }, [deviceId]);

  // Función de detención de sesión
  const sendStop = useCallback(() => {
    const token =
      typeof localStorage !== 'undefined'
        ? localStorage.getItem('flixhn_token') || localStorage.getItem('token')
        : null;

    const payload = { device_id: deviceId };
    const payloadStr = JSON.stringify(payload);
    const baseUrl = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '');
    const url = `${baseUrl}/telemetry/stop`;

    // 1. Envío prioritario con cliente Axios
    try {
      api.post('/telemetry/stop', payload).catch(() => {});
    } catch (e) {}

    // 2. Fetch con keepalive para eventos de cierre de ventana / navegación
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
  }, [deviceId]);

  // Intervalo de 15 segundos MIENTRAS el video esté en reproducción activa o pausado
  useEffect(() => {
    if (!enabled || !title || playbackState === 'stopped') {
      return;
    }

    // Enviar latido inmediato ante cambios de estado o inicio
    sendHeartbeat();

    const interval = setInterval(() => {
      sendHeartbeat();
    }, 15000);

    return () => {
      clearInterval(interval);
    };
  }, [enabled, title, playbackState, sendHeartbeat]);

  // Limpieza al desmontar el componente o cerrar la pestaña
  useEffect(() => {
    const handleUnload = () => {
      sendStop();
    };

    window.addEventListener('beforeunload', handleUnload);
    window.addEventListener('pagehide', handleUnload);

    return () => {
      window.removeEventListener('beforeunload', handleUnload);
      window.removeEventListener('pagehide', handleUnload);
      sendStop();
    };
  }, [sendStop]);

  return { deviceId, sendHeartbeat, sendStop };
}
