import React, { useRef, useEffect, useCallback } from 'react';
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
export function useTelemetryHeartbeat({
  mediaType = 'vod', // 'vod' | 'livetv'
  mediaId,
  channelId,
  title,
  poster,
  currentTime = 0,
  duration = 0,
  playbackState = 'playing', // 'playing' | 'paused' | 'stopped'
  onRemoteCommand,
  onBlocked,
  enabled = true,
} = {}) {
  // 1. REGLA ESTRICTA DE HOOKS: Todos los useRef al inicio absoluto del cuerpo de la función
  // NINGÚN if (...) return antes de llamar a los Hooks de React.
  const heartbeatRef = useRef(null);
  const isPlayingRef = useRef(playbackState === 'playing');
  const deviceIdRef = useRef(null);
  const latestProps = useRef({});

  // Inicializar identificador de dispositivo persistente en ref
  if (!deviceIdRef.current) {
    deviceIdRef.current = getPersistentDeviceId();
  }
  const deviceId = deviceIdRef.current;

  const effectiveId = channelId ?? mediaId ?? null;

  // Mantener las propiedades actualizadas en la referencia mutable
  latestProps.current = {
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
  };

  // 2. Sincronización continua de estado de reproducción
  useEffect(() => {
    isPlayingRef.current = playbackState === 'playing';
  });

  // 3. Callback para envío de latido con validaciones internas
  const sendHeartbeat = useCallback(async () => {
    const p = latestProps.current;

    // Validación interna: no enviar si está deshabilitado, sin título o detenido
    if (!p.enabled || !p.title || p.playbackState === 'stopped') {
      return;
    }

    // Validación interna: si es LiveTV y aún no cuenta con channelId/mediaId, no enviar latidos incompletos
    const currentId = p.channelId ?? p.mediaId;
    if (p.mediaType === 'livetv' && !currentId) {
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

  // 4. Callback para envío de señal de detención al desmontar o salir
  const sendStop = useCallback(() => {
    const token =
      typeof localStorage !== 'undefined'
        ? localStorage.getItem('flixhn_token') || localStorage.getItem('token')
        : null;

    const payload = { device_id: deviceId };
    const payloadStr = JSON.stringify(payload);
    const baseUrl = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '');
    const url = `${baseUrl}/telemetry/stop`;

    // Envío prioritario con Axios
    try {
      api.post('/telemetry/stop', payload).catch(() => {});
    } catch (e) {}

    // Fetch con keepalive para eventos de cierre de ventana / navegación
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

  // 5. Intervalo de 15 segundos MIENTRAS el video esté en reproducción activa o pausado
  // TODAS las validaciones de guardado van DENTRO del cuerpo del useEffect
  useEffect(() => {
    if (!enabled || !title || playbackState === 'stopped') {
      return;
    }

    // Validación interna para LiveTV sin canal definido aún
    if (mediaType === 'livetv' && !effectiveId) {
      return;
    }

    // Enviar latido inicial de inmediato
    sendHeartbeat();

    // Intervalo de latido recurrente cada 15s
    heartbeatRef.current = setInterval(() => {
      sendHeartbeat();
    }, 15000);

    return () => {
      if (heartbeatRef.current) {
        clearInterval(heartbeatRef.current);
        heartbeatRef.current = null;
      }
    };
  }, [enabled, title, playbackState, mediaType, effectiveId, sendHeartbeat]);

  // 6. Limpieza al desmontar el componente o cerrar la pestaña / ventana
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

export default useTelemetryHeartbeat;
