// Polyfill para crypto.randomUUID en entornos HTTP no seguros
if (typeof window !== 'undefined' && (!window.crypto || !window.crypto.randomUUID)) {
  if (!window.crypto) window.crypto = {};
  window.crypto.randomUUID = function () {
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
      (+c ^ (Math.random() * 16 >> (+c / 4))).toString(16)
    );
  };
}

import React, { useRef, useEffect, useState, useCallback } from 'react';
import Hls from 'hls.js';
import {
  ArrowLeft,
  Play,
  Pause,
  Volume2,
  VolumeX,
  Maximize,
  Minimize,
  Tv,
  X,
  Layers,
  Search,
  ChevronRight,
  ChevronLeft,
  SkipBack,
  SkipForward,
  AlertCircle,
  RefreshCw,
} from 'lucide-react';
import useTelemetryHeartbeat from '../hooks/useTelemetryHeartbeat';
import api from '../services/api';

function obtenerMpegTs() {
  return new Promise((resolve) => {
    if (typeof window !== 'undefined' && window.mpegts) {
      return resolve(window.mpegts);
    }
    const scriptExistente = document.getElementById('mpegts-cdn-script');
    if (scriptExistente) {
      scriptExistente.addEventListener('load', () => resolve(window.mpegts || null));
      scriptExistente.addEventListener('error', () => resolve(null));
      return;
    }
    const script = document.createElement('script');
    script.id = 'mpegts-cdn-script';
    script.src = 'https://cdn.jsdelivr.net/npm/mpegts.js@1.7.3/dist/mpegts.min.js';
    script.async = true;
    script.onload = () => resolve(window.mpegts || null);
    script.onerror = () => resolve(null);
    document.head.appendChild(script);
  });
}

// Detectar si la URL es flujo TS directo o si HLS.js falló por formato de contenedor
async function iniciarConMpegTs(url, video, onSuccess, onError) {
  const mpegts = await obtenerMpegTs();
  if (!video || !mpegts || !mpegts.getFeatureList?.()?.mseLivePlayback) {
    if (typeof onError === 'function') {
      onError('El navegador no soporta MSE Live Playback o no se pudo cargar mpegts.js');
    }
    return null;
  }

  const player = mpegts.createPlayer(
    {
      type: 'mpegts', // soporta .ts y flujos HTTP continuos
      isLive: true,
      url: url,
      cors: true,
    },
    {
      enableWorker: true,
      enableStashBuffer: false,
      stashInitialSize: 128,
      liveBufferLatencyChasing: true,
      liveBufferLatencyMaxLatency: 3.0,
      liveBufferLatencyMinRemain: 0.5,
    }
  );

  player.attachMediaElement(video);
  player.load();
  player
    .play()
    .then(onSuccess)
    .catch(() => {
      video.muted = true;
      video
        .play()
        .then(onSuccess)
        .catch(onError);
    });

  player.on(mpegts.Events.ERROR, (errType, errDetail) => {
    console.error('[FlixHN mpegts] Error:', errType, errDetail);
    if (typeof onError === 'function') {
      onError(`${errType}: ${errDetail}`);
    }
  });

  return player;
}

export default function LiveTvPlayer({
  channel: initialChannel,
  channels = [],
  onClose,
  onSelectChannel,
}) {
  // Volumen guardado o 1.0 por defecto
  const getSavedVolume = () => {
    try {
      const v = localStorage.getItem('livetv_volume') ?? localStorage.getItem('volume');
      if (v !== null) {
        const num = parseFloat(v);
        if (!isNaN(num) && num >= 0 && num <= 1) {
          return num;
        }
      }
    } catch (e) {}
    return 1.0;
  };

  const [currentChannel, setCurrentChannel] = useState(initialChannel);
  const [isPlaying, setIsPlaying] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [volume, setVolume] = useState(getSavedVolume);
  const [needsUnmutePrompt, setNeedsUnmutePrompt] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [showControls, setShowControls] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [streamError, setStreamError] = useState(null);
  const [showZappingDrawer, setShowZappingDrawer] = useState(false);
  const [zappingSearch, setZappingSearch] = useState('');
  const [selectedZappingCategory, setSelectedZappingCategory] = useState('all');

  const videoRef = useRef(null);
  const playerContainerRef = useRef(null);
  const hlsRef = useRef(null);
  const mpegtsRef = useRef(null);
  const urlActualRef = useRef(null);
  const bloqueadoPorCambioRef = useRef(false);
  const intentosRecuperacionRef = useRef(0);
  const controlsTimeoutRef = useRef(null);
  const currentPlaySessionRef = useRef(null);
  const currentDeviceIdRef = useRef('flixhn-web-client');
  const currentTuneRef = useRef(null);
  const iniciarStreamLimpiamenteRef = useRef(null);
  const volumeRef = useRef(volume);
  const isMutedRef = useRef(isMuted);

  // Mantener referencias actualizadas de volumen y mute para evitar recreación de efectos
  useEffect(() => {
    volumeRef.current = volume;
  }, [volume]);

  useEffect(() => {
    isMutedRef.current = isMuted;
  }, [isMuted]);

  // Actualizar canal actual cuando cambie la prop externa (comparando identificador, no referencia de objeto)
  useEffect(() => {
    if (initialChannel) {
      const idActual = currentChannel?.id || currentChannel?.emby_channel_id || currentChannel?.name;
      const idNuevo = initialChannel?.id || initialChannel?.emby_channel_id || initialChannel?.name;
      if (idNuevo && idNuevo !== idActual) {
        setCurrentChannel(initialChannel);
      }
    }
  }, [initialChannel?.id, initialChannel?.emby_channel_id, initialChannel?.url, initialChannel?.stream_url]);

  // Sincronizar estado isPlaying con eventos nativos del elemento de video
  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    const handlePlay = () => setIsPlaying(true);
    const handlePause = () => setIsPlaying(false);

    video.addEventListener('play', handlePlay);
    video.addEventListener('pause', handlePause);

    return () => {
      video.removeEventListener('play', handlePlay);
      video.removeEventListener('pause', handlePause);
    };
  }, []);

  // Notificar a Emby para liberar la codificación activa
  const releaseActiveEncoding = useCallback((playSessionId, deviceId = 'flixhn-web-client') => {
    if (!playSessionId) return;

    // 1. Notificar al backend de FlixHN
    api.delete('/livetv/active-encoding', {
      params: { PlaySessionId: playSessionId, DeviceId: deviceId },
    }).catch(() => {});

    // 2. Notificar directamente a Emby si hay conectividad
    try {
      const serverHost = localStorage.getItem('media_server_url') || localStorage.getItem('emby_server_url') || 'http://45.4.87.126:6789';
      const apiKey = localStorage.getItem('emby_api_key') || localStorage.getItem('media_server_api_key') || '0fe0eb20e78f431d9fa474e05ab2d82f';
      const cleanBase = serverHost.replace(/\/emby\/?$/i, '');
      const deleteUrl = `${cleanBase}/emby/Videos/ActiveEncodings?DeviceId=${encodeURIComponent(deviceId)}&PlaySessionId=${encodeURIComponent(playSessionId)}&api_key=${encodeURIComponent(apiKey)}`;
      fetch(deleteUrl, { method: 'DELETE', keepalive: true, mode: 'no-cors' }).catch(() => {});
    } catch (e) {}
  }, []);

  // Telemetría en tiempo real: Heartbeat cada 15 segundos y stop al desmontar (Cero alert nativo)
  const channelTitle = currentChannel
    ? `${currentChannel.channel_number ? `CH ${currentChannel.channel_number} - ` : ''}${currentChannel.name || 'Canal en Vivo'}`
    : 'Canal en Vivo';

  const channelPoster = currentChannel?.logo_url || '';

  useTelemetryHeartbeat({
    mediaType: 'livetv',
    mediaId: currentChannel?.id,
    title: channelTitle,
    poster: channelPoster,
    currentTime: () => videoRef.current?.currentTime || 0,
    duration: 0,
    playbackState: isPlaying ? 'playing' : 'paused',
    onRemoteCommand: (command) => {
      if (command === 'pause') {
        videoRef.current?.pause();
        setIsPlaying(false);
      } else if (command === 'resume') {
        videoRef.current?.play().catch(() => {});
        setIsPlaying(true);
      } else if (command === 'stop' || command === 'kick') {
        if (typeof onClose === 'function') onClose();
      }
    },
    onBlocked: () => {
      // Reemplazo estricto de alert() por mensaje en interfaz
      setStreamError('El acceso a la transmisión en directo ha sido suspendido para este dispositivo por el administrador.');
      if (typeof onClose === 'function') {
        setTimeout(onClose, 3000);
      }
    },
    enabled: !!currentChannel,
  });

  // Manejo de Inactividad de Ratón para Ocultar Controles
  const handleMouseMove = useCallback(() => {
    setShowControls(true);
    if (controlsTimeoutRef.current) {
      clearTimeout(controlsTimeoutRef.current);
    }
    controlsTimeoutRef.current = setTimeout(() => {
      if (!showZappingDrawer) {
        setShowControls(false);
      }
    }, 4000);
  }, [showZappingDrawer]);

  // 2. IGNORAR AbortError Y VALIDAR SI EL VIDEO REALMENTE SE DETUVO ANTES DE MOSTRAR ERROR
  const iniciarReproduccionSegura = useCallback((video) => {
    if (!video) return;

    video.volume = volumeRef.current;
    video.muted = isMutedRef.current;

    const playPromise = video.play();
    if (playPromise !== undefined) {
      playPromise
        .then(() => {
          setIsPlaying(true);
          setIsLoading(false);
          setStreamError(null);
        })
        .catch((err) => {
          if (err.name === 'AbortError') {
            // Petición interrumpida por zapping rápido: ignorar silenciosamente
            return;
          }
          console.warn('[FlixHN] Autoplay con audio bloqueado por el navegador:', err);
          // Si el navegador bloquea autoplay con sonido, iniciar muteado
          video.muted = true;
          isMutedRef.current = true;
          setIsMuted(true);

          video
            .play()
            .then(() => {
              setIsPlaying(true);
              setIsLoading(false);
              setStreamError(null);
              setNeedsUnmutePrompt(true);
            })
            .catch((e) => {
              if (e.name === 'AbortError') return;
              console.warn('[FlixHN] Autoplay silenciado también falló:', e);
              if (video.paused) {
                setIsPlaying(false);
              }
            });
        });
    }
  }, []);

  // Botón flotante: "Activar Sonido"
  const handleEnableSound = () => {
    const video = videoRef.current;
    if (!video) return;

    video.muted = false;
    isMutedRef.current = false;
    const targetVol = volume > 0 ? volume : 1.0;
    video.volume = targetVol;
    volumeRef.current = targetVol;
    setVolume(targetVol);
    setIsMuted(false);
    setNeedsUnmutePrompt(false);
    try {
      localStorage.setItem('livetv_volume', String(targetVol));
    } catch (e) {}
  };

  // 3. LIMPIEZA AL CAMBIAR DE CANAL (CH + / CH - O CLIC EN GUÍA) Y REPRODUCCIÓN HLS ESTABLE
  const iniciarStreamLimpiamente = useCallback(async (channelParam) => {
    const video = videoRef.current;
    if (!channelParam || !video) return;

    // Acepta tanto un objeto canal como una URL directa de stream
    const channelToPlay = typeof channelParam === 'string'
      ? (currentChannel || { url: channelParam, stream_url: channelParam, name: 'Canal en Vivo' })
      : channelParam;

    const tuneId = Symbol('tune');
    currentTuneRef.current = tuneId;

    bloqueadoPorCambioRef.current = true;
    setStreamError(null);
    setIsLoading(true);
    intentosRecuperacionRef.current = 0;

    // 1. Liberar sintonizador / codificación activa previa
    if (currentPlaySessionRef.current) {
      releaseActiveEncoding(currentPlaySessionRef.current);
      currentPlaySessionRef.current = null;
    }

    // 2. Destruir limpiamente instancias previas de HLS y mpegts
    if (hlsRef.current) {
      try {
        hlsRef.current.stopLoad();
        hlsRef.current.detachMedia();
        hlsRef.current.destroy();
      } catch (e) {
        console.warn('[FlixHN] Limpieza HLS previa:', e);
      }
      hlsRef.current = null;
    }

    if (mpegtsRef.current) {
      try {
        mpegtsRef.current.pause();
        mpegtsRef.current.unload();
        mpegtsRef.current.detachMediaElement();
        mpegtsRef.current.destroy();
      } catch (e) {
        console.warn('[FlixHN] Limpieza mpegts previa:', e);
      }
      mpegtsRef.current = null;
    }

    // 3. Resetear elemento de video sin disparar falsos errores
    try {
      video.pause();
      video.removeAttribute('src');
      video.load();
    } catch (e) {
      console.warn('[FlixHN] Error reseteando elemento de video:', e);
    }

    // Pequeña espera para asegurar que el navegador liberó el MediaSource anterior
    await new Promise((r) => setTimeout(r, 60));
    if (currentTuneRef.current !== tuneId) return;

    const targetChannelId = channelToPlay.emby_channel_id || channelToPlay.id || channelToPlay.tvg_id;
    const deviceId = currentDeviceIdRef.current;
    const directStreamUrl = typeof channelParam === 'string' ? channelParam : (channelToPlay.url || channelToPlay.stream_url);

    let finalHlsUrl = typeof channelParam === 'string' && (channelParam.includes('.m3u8') || channelParam.includes('/master') || channelParam.includes('/Videos/') || channelParam.includes('output=hls'))
      ? channelParam
      : null;
    let playSessionId = null;

    // 4. Negociar PlaybackInfo con Emby a través del backend de FlixHN (solo si no se pasó ya una URL directa)
    if (!finalHlsUrl) {
      try {
        const res = await api.post('/livetv/playback-info', {
          channel_id: targetChannelId,
          device_id: deviceId,
          stream_url: directStreamUrl,
        });

        if (currentTuneRef.current !== tuneId) return;

        if (res.data && res.data.hls_url) {
          finalHlsUrl = res.data.hls_url;
          playSessionId = res.data.play_session_id;
        }
      } catch (err) {
        console.warn('[FlixHN] Falló consulta PlaybackInfo en backend, evaluando fallback:', err);
      }
    }

    if (currentTuneRef.current !== tuneId) return;

    // 5. Fallback directo a Emby API si el backend no pudo negociar y hay emby_channel_id
    if (!finalHlsUrl && (channelToPlay.emby_channel_id || targetChannelId)) {
      const serverHost = localStorage.getItem('media_server_url') || localStorage.getItem('emby_server_url') || 'http://45.4.87.126:6789';
      const apiKey = localStorage.getItem('emby_api_key') || localStorage.getItem('media_server_api_key') || '0fe0eb20e78f431d9fa474e05ab2d82f';
      const cleanBaseUrl = serverHost.replace(/\/emby\/?$/i, '');
      const embyChannelId = channelToPlay.emby_channel_id || targetChannelId;

      try {
        const deviceProfile = {
          Name: 'FlixHN Live Player',
          MaxStreamingBitrate: 20000000,
          DirectPlayProfiles: [
            { Container: 'ts,m3u8,mp4', Type: 'Video', VideoCodec: 'h264', AudioCodec: 'aac,mp3' },
          ],
          TranscodingProfiles: [
            {
              Container: 'ts',
              Type: 'Video',
              VideoCodec: 'h264',
              AudioCodec: 'aac',
              AudioBitrate: 192000,
              MaxAudioChannels: '2',
              Protocol: 'hls',
              Context: 'Streaming',
              BreakOnNonKeyFrames: true,
            },
          ],
          CodecProfiles: [
            { Type: 'Video', Codec: 'h264' },
            { Type: 'Audio', Codec: 'aac', Conditions: [{ Condition: 'LessThanEqual', Property: 'AudioChannels', Value: '2' }] },
          ],
        };

        const embyRes = await fetch(`${cleanBaseUrl}/emby/Items/${embyChannelId}/PlaybackInfo?api_key=${apiKey}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Emby-Token': apiKey,
          },
          body: JSON.stringify({ DeviceProfile: deviceProfile }),
        });

        if (currentTuneRef.current === tuneId && embyRes.ok) {
          const embyData = await embyRes.json();
          playSessionId = embyData.PlaySessionId;
          const ms = embyData.MediaSources?.[0];
          const msId = ms?.Id || embyChannelId;
          const liveStreamId = ms?.LiveStreamId;
          const transUrl = ms?.TranscodingUrl;

          if (transUrl) {
            const rawUrl = transUrl.startsWith('http')
              ? transUrl
              : `${cleanBaseUrl}${transUrl.startsWith('/') ? '' : '/'}${transUrl}`;
            const parsed = new URL(rawUrl);
            parsed.searchParams.set('MediaSourceId', msId);
            parsed.searchParams.set('PlaySessionId', playSessionId || ('flixhn_' + Math.random().toString(36).substring(2, 9)));
            if (liveStreamId) parsed.searchParams.set('LiveStreamId', liveStreamId);
            parsed.searchParams.set('VideoCodec', 'h264');
            parsed.searchParams.set('AudioCodec', 'aac');
            parsed.searchParams.set('AudioBitrate', '192000');
            parsed.searchParams.set('TranscodingMaxAudioChannels', '2');
            parsed.searchParams.set('SegmentContainer', 'ts');
            parsed.searchParams.set('BreakOnNonKeyFrames', 'True');
            parsed.searchParams.set('DeviceId', deviceId);
            parsed.searchParams.set('api_key', apiKey);
            finalHlsUrl = parsed.toString();
          } else {
            const params = new URLSearchParams({
              MediaSourceId: msId,
              PlaySessionId: playSessionId || ('flixhn_' + Math.random().toString(36).substring(2, 9)),
              VideoCodec: 'h264',
              AudioCodec: 'aac',
              AudioBitrate: '192000',
              TranscodingMaxAudioChannels: '2',
              SegmentContainer: 'ts',
              BreakOnNonKeyFrames: 'True',
              DeviceId: deviceId,
              api_key: apiKey,
            });
            if (liveStreamId) params.set('LiveStreamId', liveStreamId);
            finalHlsUrl = `${cleanBaseUrl}/emby/Videos/${embyChannelId}/master.m3u8?${params.toString()}`;
          }
        }
      } catch (e) {
        console.warn('[FlixHN] Error en negociación directa con Emby:', e);
      }
    }

    if (currentTuneRef.current !== tuneId) return;

    // 6. Último fallback: usar stream_url directo del canal
    if (!finalHlsUrl) {
      finalHlsUrl = directStreamUrl;
    }

    if (!finalHlsUrl) {
      setStreamError('No se encontró una señal de transmisión válida para este canal.');
      setIsLoading(false);
      bloqueadoPorCambioRef.current = false;
      return;
    }

    currentPlaySessionRef.current = playSessionId;

    // 7. Instanciar motor de reproducción: mpegts.js para flujos .ts continuos o HLS.js para .m3u8
    const canPlayNativeHls = video.canPlayType('application/vnd.apple.mpegurl');
    const isHlsUrl =
      finalHlsUrl.includes('.m3u8') ||
      finalHlsUrl.includes('output=hls') ||
      finalHlsUrl.includes('/master') ||
      finalHlsUrl.includes('/Videos/');

    // Detectar si la URL es flujo TS directo o streaming continuo no HLS
    const isDirectTs =
      /\.ts($|\?)/i.test(finalHlsUrl) ||
      (!isHlsUrl && (finalHlsUrl.includes(':') || finalHlsUrl.startsWith('http')));

    const setCargando = (val) => setIsLoading(Boolean(val));
    const setErrorCanal = (err) => {
      if (!err) {
        setStreamError(null);
      } else if (typeof err === 'string') {
        setStreamError(err);
      } else {
        setStreamError('No se pudo establecer la conexión con la señal en directo de este canal.');
      }
    };

    // CASO A: Flujo MPEG-TS directo (.ts o continuo sin .m3u8) con mpegts.js
    if (isDirectTs && !isHlsUrl) {
      console.info('[FlixHN] Canal detectado como flujo continuo / MPEG-TS (.ts). Iniciando con mpegts.js:', finalHlsUrl);
      bloqueadoPorCambioRef.current = false;
      iniciarConMpegTs(
        finalHlsUrl,
        video,
        () => {
          if (currentTuneRef.current !== tuneId) return;
          setCargando(false);
          setErrorCanal(false);
          setIsPlaying(true);
        },
        (motivo) => {
          if (currentTuneRef.current !== tuneId) return;
          setCargando(false);
          const motivoStr = String(motivo || '');
          const esIncompatibilidadCodec =
            /codec|notsupported|hevc|hvc1|ac-3|ec-3|eac3|media_mse_error|formaterror|unsupported/i.test(motivoStr);

          if (esIncompatibilidadCodec) {
            setErrorCanal(
              'Formato de video/audio del canal (HEVC/AC3) incompatible con este navegador. Prueba desde la App Android TV o activa transcodificación.'
            );
          } else {
            setErrorCanal(`Señal no compatible con navegador web (${motivoStr})`);
          }
        }
      ).then((player) => {
        if (currentTuneRef.current !== tuneId) {
          if (player) {
            try {
              player.pause();
              player.unload();
              player.detachMediaElement();
              player.destroy();
            } catch (e) {}
          }
          return;
        }
        mpegtsRef.current = player;
      });
      return;
    }

    // CASO B: Canal HLS (.m3u8) con HLS.js y fallback rápido ante códec incompatible
    if (typeof Hls !== 'undefined' && Hls.isSupported() && (isHlsUrl || !canPlayNativeHls)) {
      const inicioCargaTimestamp = Date.now();
      const hls = new Hls({
        enableWorker: true,
        lowLatencyMode: false,
        backBufferLength: 15,
        maxBufferLength: 25,
        maxMaxBufferLength: 45,
        maxBufferHole: 1.5,        // Tolera huecos de hasta 1.5s en streams IPTV sin cerrar MediaSource
        nudgeOffset: 0.2,
        nudgeMaxRetry: 10,
        fragLoadingMaxRetry: 5,
        manifestLoadingMaxRetry: 5,
        levelLoadingMaxRetry: 5,
        startFragPrefetch: true,
        manifestLoadingTimeOut: 15000,
        levelLoadingTimeOut: 15000,
        fragLoadingTimeOut: 20000,
      });

      hlsRef.current = hls;
      const url = finalHlsUrl;
      hls.loadSource(url);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        if (currentTuneRef.current !== tuneId) return;
        bloqueadoPorCambioRef.current = false;
        iniciarReproduccionSegura(video);
      });

      let resetCount = 0;
      let lastResetTime = 0;

      hls.on(Hls.Events.ERROR, (event, data) => {
        if (currentTuneRef.current !== tuneId) return;
        if (!data.fatal) return;

        const ahora = Date.now();
        const tiempoTranscurrido = ahora - inicioCargaTimestamp;
        const motivoTecnico = data.reason || data.err?.message || data.details || 'Error desconocido';

        console.warn('[FlixHN] HLS Error Fatal:', data.details, motivoTecnico, `(${tiempoTranscurrido}ms desde inicio)`);

        // DETECCIÓN DE CÓDEC INCOMPATIBLE (H.265 / AC3) O CONTENEDOR NO SOPORTADO
        if (
          data.details === 'mediaSourceRequiresReset' ||
          data.details === 'bufferAddCodecError' ||
          data.details === 'bufferIncompatibleCodecsError' ||
          data.details === 'manifestParsingError'
        ) {
          console.warn('[FlixHN] Detalle técnico MSE:', data.details, motivoTecnico);

          // Si falló inmediatamente al arrancar (< 2.5s) o bufferAddCodecError directo:
          // es incompatibilidad de códec/contenedor con MediaSource, no caída temporal
          if (tiempoTranscurrido < 2500 || data.details === 'bufferAddCodecError') {
            console.info('[FlixHN] Falla temprana detectada en HLS (< 2.5s). Cambiando automáticamente a motor MPEG-TS / Fallback...');
            try {
              hls.stopLoad();
              hls.detachMedia();
              hls.destroy();
            } catch (e) {}
            hlsRef.current = null;

            if (currentTuneRef.current !== tuneId) return;

            iniciarConMpegTs(
              url,
              videoRef.current,
              () => {
                if (currentTuneRef.current !== tuneId) return;
                setCargando(false);
                setErrorCanal(false);
                setIsPlaying(true);
              },
              (motivo) => {
                if (currentTuneRef.current !== tuneId) return;
                setCargando(false);
                const motivoStr = String(motivo || '');
                const esIncompatibilidadCodec =
                  /codec|notsupported|hevc|hvc1|ac-3|ec-3|eac3|media_mse_error|formaterror|unsupported/i.test(motivoStr) ||
                  data.details === 'bufferAddCodecError' ||
                  data.details === 'mediaSourceRequiresReset';

                if (esIncompatibilidadCodec) {
                  setErrorCanal(
                    'Formato de video/audio del canal (HEVC/AC3) incompatible con este navegador. Prueba desde la App Android TV o activa transcodificación.'
                  );
                } else {
                  setErrorCanal(`Señal no compatible con navegador web (${motivoStr || motivoTecnico})`);
                }
              }
            ).then((player) => {
              if (currentTuneRef.current !== tuneId) {
                if (player) {
                  try {
                    player.pause();
                    player.unload();
                    player.detachMediaElement();
                    player.destroy();
                  } catch (e) {}
                }
                return;
              }
              mpegtsRef.current = player;
            });
            return;
          }
        }

        // Reiniciar contador si han pasado más de 15 segundos de reproducción estable
        if (ahora - lastResetTime > 15000) {
          resetCount = 0;
        }

        // CASO: MediaSource cerrado tras reproducción activa (> 2.5s)
        if (data.details === 'mediaSourceRequiresReset') {
          resetCount++;
          lastResetTime = ahora;

          if (resetCount <= 2) {
            console.warn(`[FlixHN] MediaSource cerrado durante reproducción, re-adjuntando video (${resetCount}/2)...`);
            hls.detachMedia();
            setTimeout(() => {
              if (hlsRef.current === hls && videoRef.current && currentTuneRef.current === tuneId) {
                hls.attachMedia(videoRef.current);
                hls.loadSource(url);
              }
            }, 150);
          } else {
            console.error('[FlixHN] No se pudo estabilizar el MediaSource tras reintentos. Probando fallback MPEG-TS...');
            try {
              hls.stopLoad();
              hls.detachMedia();
              hls.destroy();
            } catch (e) {}
            hlsRef.current = null;

            iniciarConMpegTs(
              url,
              videoRef.current,
              () => {
                if (currentTuneRef.current !== tuneId) return;
                setCargando(false);
                setErrorCanal(false);
                setIsPlaying(true);
              },
              (motivo) => {
                if (currentTuneRef.current !== tuneId) return;
                setCargando(false);
                setErrorCanal(
                  'Formato de video/audio del canal (HEVC/AC3) incompatible con este navegador. Prueba desde la App Android TV o activa transcodificación.'
                );
              }
            ).then((player) => {
              if (currentTuneRef.current !== tuneId) {
                if (player) {
                  try {
                    player.pause();
                    player.unload();
                    player.detachMediaElement();
                    player.destroy();
                  } catch (e) {}
                }
                return;
              }
              mpegtsRef.current = player;
            });
          }
          return;
        }

        // RESTO DE ERRORES FATALES
        switch (data.type) {
          case Hls.ErrorTypes.NETWORK_ERROR:
            console.warn('[FlixHN] Error de red, reintentando carga...');
            hls.startLoad();
            break;

          case Hls.ErrorTypes.MEDIA_ERROR:
            resetCount++;
            lastResetTime = ahora;
            if (resetCount <= 2) {
              console.warn(`[FlixHN] Recuperando decodificación (${resetCount}/2)...`);
              hls.recoverMediaError();
            } else {
              // Reconstruir completamente la instancia HLS
              if (iniciarStreamLimpiamenteRef.current) {
                iniciarStreamLimpiamenteRef.current(url);
              } else {
                iniciarStreamLimpiamente(url);
              }
            }
            break;

          default:
            setCargando(false);
            setErrorCanal(motivoTecnico ? `Error en señal: ${motivoTecnico}` : true);
            break;
        }
      });
    } else if (canPlayNativeHls || video.canPlayType('application/vnd.apple.mpegurl')) {
      // Fallback nativo (Safari / iOS)
      video.src = finalHlsUrl;
      bloqueadoPorCambioRef.current = false;
      iniciarReproduccionSegura(video);
    } else {
      video.src = finalHlsUrl;
      bloqueadoPorCambioRef.current = false;
      iniciarReproduccionSegura(video);
    }
  }, [currentChannel, iniciarReproduccionSegura, releaseActiveEncoding]);

  iniciarStreamLimpiamenteRef.current = iniciarStreamLimpiamente;

  // Cadena URL estable para la dependencia exclusiva del useEffect
  const canalActivoUrl = currentChannel?.url || currentChannel?.stream_url || (currentChannel?.id ? `channel-${currentChannel.id}` : '');

  // 1. EVITAR EL BUCLE DE RE-RENDERIZADO EN REACT (useEffect)
  useEffect(() => {
    if (!canalActivoUrl) return;

    // Si es exactamente la misma URL y ya hay una instancia activa (HLS o mpegts), NO reiniciar
    if (urlActualRef.current === canalActivoUrl && (hlsRef.current || mpegtsRef.current)) {
      return;
    }

    urlActualRef.current = canalActivoUrl;
    iniciarStreamLimpiamente(currentChannel);

    return () => {
      // Cleanup solo al desmontar el componente o cambiar de URL
      if (hlsRef.current) {
        try {
          hlsRef.current.destroy();
        } catch (e) {}
        hlsRef.current = null;
      }
      if (mpegtsRef.current) {
        try {
          mpegtsRef.current.pause();
          mpegtsRef.current.unload();
          mpegtsRef.current.detachMediaElement();
          mpegtsRef.current.destroy();
        } catch (e) {}
        mpegtsRef.current = null;
      }
      if (currentTuneRef.current) {
        currentTuneRef.current = null;
      }
      if (currentPlaySessionRef.current) {
        releaseActiveEncoding(currentPlaySessionRef.current);
        currentPlaySessionRef.current = null;
      }
    };
  }, [canalActivoUrl]); // Depender EXCLUSIVAMENTE de la cadena URL, nunca del objeto completo

  // Botón manual de Reintentar Señal (evita bucles y fuerza re-sintonización)
  const handleReintentar = useCallback(() => {
    urlActualRef.current = null;
    intentosRecuperacionRef.current = 0;
    setStreamError(null);
    setIsLoading(true);
    if (hlsRef.current) {
      try {
        hlsRef.current.destroy();
      } catch (e) {}
      hlsRef.current = null;
    }
    if (mpegtsRef.current) {
      try {
        mpegtsRef.current.pause();
        mpegtsRef.current.unload();
        mpegtsRef.current.detachMediaElement();
        mpegtsRef.current.destroy();
      } catch (e) {}
      mpegtsRef.current = null;
    }
    if (currentChannel) {
      iniciarStreamLimpiamente(currentChannel);
    }
  }, [currentChannel, iniciarStreamLimpiamente]);

  // Liberar codificación si el usuario cierra o recarga la pestaña
  useEffect(() => {
    const handleBeforeUnload = () => {
      if (currentPlaySessionRef.current) {
        releaseActiveEncoding(currentPlaySessionRef.current);
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [releaseActiveEncoding]);

  // Play / Pausa
  const togglePlay = () => {
    if (!videoRef.current) return;
    if (videoRef.current.paused) {
      if (mpegtsRef.current) {
        try {
          mpegtsRef.current.play();
        } catch (e) {}
      }
      videoRef.current.play().then(() => setIsPlaying(true)).catch(() => {});
    } else {
      if (mpegtsRef.current) {
        try {
          mpegtsRef.current.pause();
        } catch (e) {}
      }
      videoRef.current.pause();
      setIsPlaying(false);
    }
  };

  // Mute / Unmute
  const toggleMute = () => {
    if (!videoRef.current) return;
    const nextMuted = !isMuted;
    videoRef.current.muted = nextMuted;
    setIsMuted(nextMuted);
    isMutedRef.current = nextMuted;

    if (!nextMuted) {
      setNeedsUnmutePrompt(false);
      const targetVol = volume === 0 ? 0.5 : volume;
      videoRef.current.volume = targetVol;
      setVolume(targetVol);
      volumeRef.current = targetVol;
      try {
        localStorage.setItem('livetv_volume', String(targetVol));
      } catch (e) {}
    }
  };

  // Cambio de Volumen con slider
  const handleVolumeChange = (e) => {
    const val = parseFloat(e.target.value);
    setVolume(val);
    volumeRef.current = val;
    try {
      localStorage.setItem('livetv_volume', String(val));
    } catch (err) {}

    if (videoRef.current) {
      videoRef.current.volume = val;
      const nextMuted = val === 0;
      videoRef.current.muted = nextMuted;
      setIsMuted(nextMuted);
      isMutedRef.current = nextMuted;
      if (val > 0) {
        setNeedsUnmutePrompt(false);
      }
    }
  };

  // Pantalla Completa
  const toggleFullscreen = () => {
    if (!playerContainerRef.current) return;
    if (!document.fullscreenElement) {
      playerContainerRef.current.requestFullscreen().then(() => setIsFullscreen(true)).catch(() => {});
    } else {
      document.exitFullscreen().then(() => setIsFullscreen(false)).catch(() => {});
    }
  };

  // Navegación circular de canales: +1 (Siguiente) | -1 (Anterior)
  const cambiarCanalRelativo = useCallback(
    (direccion) => {
      if (!channels || channels.length === 0) return;

      const indiceActual = channels.findIndex(
        (c) =>
          (c.id && currentChannel?.id && c.id === currentChannel.id) ||
          (c.channel_number && currentChannel?.channel_number && c.channel_number === currentChannel.channel_number) ||
          c.name === currentChannel?.name
      );

      let nuevoIndice = (indiceActual >= 0 ? indiceActual : 0) + direccion;

      // Navegación circular: si está en el último pasa al primero, y viceversa
      if (nuevoIndice >= channels.length) nuevoIndice = 0;
      if (nuevoIndice < 0) nuevoIndice = channels.length - 1;

      const siguienteCanal = channels[nuevoIndice];
      if (!siguienteCanal) return;

      setCurrentChannel(siguienteCanal);
      if (typeof onSelectChannel === 'function') {
        onSelectChannel(siguienteCanal);
      }
    },
    [channels, currentChannel, onSelectChannel]
  );

  // Atajos de Teclado y Control Remoto TV Box (ArrowUp, ArrowDown, PageUp, PageDown, ChannelUp, ChannelDown, etc.)
  useEffect(() => {
    const handleKeyDown = (e) => {
      // No interferir si el usuario está buscando o escribiendo en inputs
      if (e.target && ['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;

      if (
        e.key === 'ArrowUp' ||
        e.key === 'PageUp' ||
        e.key === 'ChannelUp' ||
        e.key === 'ArrowRight'
      ) {
        e.preventDefault();
        cambiarCanalRelativo(1);
      } else if (
        e.key === 'ArrowDown' ||
        e.key === 'PageDown' ||
        e.key === 'ChannelDown' ||
        e.key === 'ArrowLeft'
      ) {
        e.preventDefault();
        cambiarCanalRelativo(-1);
      } else if (e.key === ' ' || e.key === 'k' || e.key === 'K') {
        e.preventDefault();
        togglePlay();
      } else if (e.key === 'm' || e.key === 'M') {
        e.preventDefault();
        toggleMute();
      } else if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        toggleFullscreen();
      } else if (e.key === 'Escape') {
        if (showZappingDrawer) {
          e.preventDefault();
          setShowZappingDrawer(false);
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [cambiarCanalRelativo, showZappingDrawer, togglePlay, toggleMute, toggleFullscreen]);

  // Zapping: Cambiar de canal sin salir del reproductor
  const handleZappingSelect = (ch) => {
    setCurrentChannel(ch);
    if (typeof onSelectChannel === 'function') {
      onSelectChannel(ch);
    }
    // Cerrar drawer en pantallas pequeñas
    if (window.innerWidth < 768) {
      setShowZappingDrawer(false);
    }
  };

  // Filtrado de canales en Zapping Drawer
  const filteredChannels = channels.filter((ch) => {
    const matchesSearch =
      ch.name.toLowerCase().includes(zappingSearch.toLowerCase()) ||
      ch.group_title?.toLowerCase().includes(zappingSearch.toLowerCase()) ||
      ch.channel_number?.toString().includes(zappingSearch);

    const matchesCategory =
      selectedZappingCategory === 'all' || ch.group_title === selectedZappingCategory;

    return matchesSearch && matchesCategory;
  });

  // Categorías únicas para Zapping
  const zappingCategories = ['all', ...new Set(channels.map((c) => c.group_title).filter(Boolean))];

  // Cálculo del índice del canal actual para el indicador CH
  const indiceCanalActivo = channels.findIndex(
    (c) =>
      (c.id && currentChannel?.id && c.id === currentChannel.id) ||
      (c.channel_number && currentChannel?.channel_number && c.channel_number === currentChannel.channel_number) ||
      c.name === currentChannel?.name
  );
  const numeroCanalMostrar =
    currentChannel?.channel_number || (indiceCanalActivo >= 0 ? indiceCanalActivo + 1 : 1);

  return (
    <div
      ref={playerContainerRef}
      onMouseMove={handleMouseMove}
      className="fixed inset-0 z-50 bg-black text-white flex items-center justify-center overflow-hidden select-none font-sans"
    >
      {/* Elemento de Video */}
      <video
        ref={videoRef}
        id="liveVideoPlayer"
        onClick={togglePlay}
        playsInline
        className="w-full h-full object-contain cursor-pointer"
        onWaiting={() => {
          if (videoRef.current && videoRef.current.readyState < 3) {
            setIsLoading(true);
          }
        }}
        onPlaying={() => {
          setIsLoading(false);
          setStreamError(null);
          setIsPlaying(true);
          intentosRecuperacionRef.current = 0;
        }}
        onTimeUpdate={() => {
          const video = videoRef.current;
          if (video && video.currentTime > 0) {
            // Blindaje de señal: si el video tiene cuadros activos, forzar siempre quitar error y spinner
            setStreamError(null);
            setIsLoading(false);
            if (!isPlaying && !video.paused) {
              setIsPlaying(true);
            }
          }
        }}
        onError={() => {
          const video = videoRef.current;
          if (video && !video.paused && video.readyState >= 3) {
            return;
          }
          if (!hlsRef.current && !mpegtsRef.current) {
            setIsLoading(false);
            setStreamError('Error al decodificar la señal de video.');
          }
        }}
      />

      {/* Flechas Laterales Flotantes para Zapping Rápido */}
      <button
        type="button"
        onClick={() => cambiarCanalRelativo(-1)}
        className={`absolute left-3 md:left-6 top-1/2 -translate-y-1/2 z-20 w-11 h-14 md:w-12 md:h-16 rounded-xl bg-black/40 hover:bg-black/80 border border-white/10 hover:border-white/30 backdrop-blur-md flex items-center justify-center text-white/70 hover:text-white transition-all duration-200 transform hover:scale-105 active:scale-95 cursor-pointer shadow-xl ${
          showControls ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'
        }`}
        title="Canal Anterior (Flecha Izquierda / Pág Arriba)"
      >
        <ChevronLeft className="w-7 h-7 md:w-8 md:h-8" />
      </button>

      <button
        type="button"
        onClick={() => cambiarCanalRelativo(1)}
        className={`absolute right-3 md:right-6 top-1/2 -translate-y-1/2 z-20 w-11 h-14 md:w-12 md:h-16 rounded-xl bg-black/40 hover:bg-black/80 border border-white/10 hover:border-white/30 backdrop-blur-md flex items-center justify-center text-white/70 hover:text-white transition-all duration-200 transform hover:scale-105 active:scale-95 cursor-pointer shadow-xl ${
          showControls && !showZappingDrawer ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'
        }`}
        title="Siguiente Canal (Flecha Derecha / Pág Abajo)"
      >
        <ChevronRight className="w-7 h-7 md:w-8 md:h-8" />
      </button>

      {/* Botón flotante para activar sonido si el navegador bloqueó autoplay inicial */}
      {needsUnmutePrompt && (
        <div className="absolute top-20 left-1/2 -translate-x-1/2 z-40 animate-bounce">
          <button
            type="button"
            onClick={handleEnableSound}
            className="flex items-center gap-2 bg-[#E50914] hover:bg-[#b80710] text-white px-5 py-2.5 rounded-full shadow-2xl font-bold text-xs md:text-sm tracking-wide cursor-pointer transition-all transform hover:scale-105 active:scale-95 border border-white/20"
          >
            <Volume2 className="w-5 h-5 animate-pulse" />
            <span>Activar Sonido</span>
          </button>
        </div>
      )}

      {/* Spinner de Carga Central */}
      {isLoading && !streamError && (
        <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/60 pointer-events-none">
          <div className="relative">
            <div className="w-16 h-16 border-4 border-[#E50914] border-t-transparent rounded-full animate-spin" />
            <div className="absolute inset-0 flex items-center justify-center">
              <Tv className="w-6 h-6 text-[#E50914]" />
            </div>
          </div>
          <span className="text-sm font-bold text-white mt-4 tracking-wider">
            Sintonizando {currentChannel?.name}...
          </span>
          <span className="text-xs text-zinc-400 font-mono mt-1">
            FlixHN Live DirectPlay
          </span>
        </div>
      )}

      {/* Pantalla de Error de Transmisión */}
      {streamError && (
        <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/85 p-6 text-center space-y-4">
          <div className="w-14 h-14 rounded-full bg-red-950/80 border border-red-700 flex items-center justify-center text-red-500">
            <AlertCircle className="w-8 h-8" />
          </div>
          <div className="space-y-1 max-w-md">
            <h3 className="text-lg font-bold text-white">
              {streamError.includes('incompatible con este navegador') || streamError.includes('HEVC/AC3')
                ? 'Formato de Señal Incompatible'
                : 'Canal Temporalmente Fuera del Aire'}
            </h3>
            <p className="text-xs text-zinc-300 leading-relaxed">{streamError}</p>
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={handleReintentar}
              className="flex items-center gap-2 bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded-lg text-xs font-bold transition-colors cursor-pointer"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              Reintentar Señal
            </button>
            <button
              onClick={() => setShowZappingDrawer(true)}
              className="flex items-center gap-2 bg-zinc-800 hover:bg-zinc-700 text-white px-4 py-2 rounded-lg text-xs font-semibold transition-colors cursor-pointer"
            >
              <Layers className="w-3.5 h-3.5" />
              Cambiar de Canal
            </button>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* BARRA SUPERIOR (HEADER CONTROLS) */}
      {/* ========================================================================= */}
      <div
        className={`absolute top-0 inset-x-0 bg-gradient-to-b from-black/90 via-black/50 to-transparent p-4 md:p-6 transition-opacity duration-300 flex items-center justify-between z-20 ${
          showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'
        }`}
      >
        <div className="flex items-center gap-3 md:gap-4 min-w-0">
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-full hover:bg-white/15 transition-colors cursor-pointer"
            title="Volver"
          >
            <ArrowLeft className="w-6 h-6" />
          </button>

          {/* Logo del Canal */}
          <div className="w-10 h-10 rounded-md bg-[#181818] border border-zinc-800 flex items-center justify-center overflow-hidden shrink-0">
            {(currentChannel?.logo || currentChannel?.logo_url || currentChannel?.tvg_logo) ? (
              <img
                src={currentChannel.logo || currentChannel.logo_url || currentChannel.tvg_logo}
                alt={currentChannel.name}
                className="w-full h-full object-contain p-1"
                onError={(e) => { e.currentTarget.style.display = 'none'; }}
              />
            ) : (
              <Tv className="w-5 h-5 text-zinc-500" />
            )}
          </div>

          {/* Nombre y Número de Canal */}
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h2 className="text-sm md:text-base font-black text-white truncate">
                {currentChannel?.name}
              </h2>
              {currentChannel?.channel_number && (
                <span className="bg-zinc-800 text-zinc-300 font-mono text-[10px] px-1.5 py-0.5 rounded font-bold border border-zinc-700">
                  CH {currentChannel.channel_number}
                </span>
              )}
            </div>
            <div className="flex items-center gap-2 text-xs text-zinc-400">
              <span>{currentChannel?.group_title || 'TV en Vivo'}</span>
              <span>•</span>
              <span className="flex items-center gap-1 font-bold text-red-500 text-[11px] uppercase tracking-wider">
                <span className="w-2 h-2 rounded-full bg-red-600 animate-pulse" />
                En Directo
              </span>
            </div>
          </div>
        </div>

        {/* Botón Zapping / Guía */}
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setShowZappingDrawer(!showZappingDrawer)}
            className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
              showZappingDrawer
                ? 'bg-[#E50914] text-white shadow-lg'
                : 'bg-black/60 hover:bg-white/20 text-white backdrop-blur-md border border-white/20'
            }`}
          >
            <Layers className="w-4 h-4" />
            <span className="hidden sm:inline">Guía de Canales (Zapping)</span>
          </button>

          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-full hover:bg-white/15 transition-colors cursor-pointer"
            title="Cerrar reproductor"
          >
            <X className="w-6 h-6" />
          </button>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* BARRA INFERIOR (FOOTER CONTROLS) */}
      {/* ========================================================================= */}
      <div
        className={`absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent p-4 md:p-6 transition-opacity duration-300 flex items-center justify-between z-20 ${
          showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'
        }`}
      >
        <div className="flex items-center gap-3 sm:gap-4 flex-wrap">
          {/* Play/Pause */}
          <button
            type="button"
            onClick={togglePlay}
            className="p-2 hover:bg-white/15 rounded-full transition-colors cursor-pointer"
            title={isPlaying ? 'Pausar' : 'Reproducir'}
          >
            {isPlaying ? <Pause className="w-6 h-6 fill-current" /> : <Play className="w-6 h-6 fill-current" />}
          </button>

          {/* Controles de Zapping Rápido: Canal Anterior / Pill CH / Canal Siguiente */}
          <div className="flex items-center gap-1 bg-black/50 border border-zinc-700/80 rounded-lg p-0.5 shadow-md">
            {/* Botón Retroceder Canal (CH -) */}
            <button
              type="button"
              onClick={() => cambiarCanalRelativo(-1)}
              className="p-1.5 hover:bg-white/15 text-zinc-300 hover:text-white rounded-md transition-colors cursor-pointer flex items-center justify-center"
              title="Canal Anterior (Flecha Izquierda / Pág Arriba)"
            >
              <SkipBack className="w-4 h-4 fill-current" />
            </button>

            {/* Indicador de Canal Activo: Pill compacto */}
            <span className="px-2 py-0.5 text-[11px] font-mono font-bold text-zinc-200 bg-zinc-800/90 rounded border border-zinc-700 select-none">
              CH {numeroCanalMostrar}
            </span>

            {/* Botón Avanzar Canal (CH +) */}
            <button
              type="button"
              onClick={() => cambiarCanalRelativo(1)}
              className="p-1.5 hover:bg-white/15 text-zinc-300 hover:text-white rounded-md transition-colors cursor-pointer flex items-center justify-center"
              title="Siguiente Canal (Flecha Derecha / Pág Abajo)"
            >
              <SkipForward className="w-4 h-4 fill-current" />
            </button>
          </div>

          {/* Volumen */}
          <div className="flex items-center gap-2 group/vol">
            <button
              type="button"
              onClick={toggleMute}
              className="p-2 hover:bg-white/15 rounded-full transition-colors cursor-pointer"
              title={isMuted ? 'Activar sonido' : 'Silenciar'}
            >
              {isMuted || volume === 0 ? <VolumeX className="w-5 h-5 text-red-500" /> : <Volume2 className="w-5 h-5" />}
            </button>
            <input
              type="range"
              min="0"
              max="1"
              step="0.05"
              value={isMuted ? 0 : volume}
              onChange={handleVolumeChange}
              className="w-16 sm:w-24 h-1 bg-zinc-600 accent-[#E50914] rounded-lg cursor-pointer"
            />
          </div>

          {/* Indicador EN VIVO */}
          <div className="flex items-center gap-1.5 bg-red-600/20 text-red-500 border border-red-600/30 px-2.5 py-1 rounded text-xs font-mono font-bold tracking-wider">
            <span className="w-2 h-2 rounded-full bg-red-600 animate-ping" />
            EN VIVO
          </div>
        </div>

        {/* Lado Derecho: Pantalla Completa */}
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={toggleFullscreen}
            className="p-2 hover:bg-white/15 rounded-full transition-colors cursor-pointer"
            title={isFullscreen ? 'Salir de pantalla completa' : 'Pantalla completa'}
          >
            {isFullscreen ? <Minimize className="w-5 h-5" /> : <Maximize className="w-5 h-5" />}
          </button>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* PANEL LATERAL DE ZAPPING (CANALES SIN SALIR DEL REPRODUCTOR) */}
      {/* ========================================================================= */}
      {showZappingDrawer && (
        <aside
          onClick={(e) => e.stopPropagation()}
          className="absolute right-0 top-0 bottom-0 w-80 sm:w-96 bg-[#141414]/95 backdrop-blur-xl border-l border-zinc-800 z-30 flex flex-col justify-between shadow-2xl animate-slideLeft"
        >
          {/* Cabecera Zapping */}
          <div className="p-4 border-b border-zinc-800 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Layers className="w-4 h-4 text-[#E50914]" />
              <h3 className="text-sm font-bold text-white">Guía de Canales en Vivo</h3>
            </div>
            <button
              onClick={() => setShowZappingDrawer(false)}
              className="p-1 text-zinc-400 hover:text-white rounded cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Buscador de Canales */}
          <div className="p-3 border-b border-zinc-800 space-y-2">
            <div className="relative">
              <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-2.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={zappingSearch}
                onChange={(e) => setZappingSearch(e.target.value)}
                placeholder="Buscar canal..."
                className="w-full bg-[#181818] border border-zinc-700 rounded-md pl-8 pr-2 py-1.5 text-xs text-white placeholder-zinc-500 outline-none"
              />
            </div>

            {/* Categorías Pills */}
            <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none text-[11px]">
              {zappingCategories.map((cat) => (
                <button
                  key={cat}
                  onClick={() => setSelectedZappingCategory(cat)}
                  className={`px-2.5 py-0.5 rounded-full whitespace-nowrap font-medium transition-colors cursor-pointer ${
                    selectedZappingCategory === cat
                      ? 'bg-[#E50914] text-white'
                      : 'bg-zinc-800 text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  {cat === 'all' ? 'Todos' : cat}
                </button>
              ))}
            </div>
          </div>

          {/* Lista de Canales con Scroll */}
          <div className="flex-1 overflow-y-auto p-2 space-y-1 divide-y divide-zinc-850">
            {filteredChannels.length === 0 ? (
              <div className="p-8 text-center text-xs text-zinc-500">
                No se encontraron canales.
              </div>
            ) : (
              filteredChannels.map((ch) => {
                const isActive = ch.id === currentChannel?.id;
                return (
                  <button
                    key={ch.id}
                    type="button"
                    onClick={() => handleZappingSelect(ch)}
                    className={`w-full text-left p-2.5 rounded-lg flex items-center justify-between gap-3 transition-colors cursor-pointer group ${
                      isActive
                        ? 'bg-[#E50914]/20 border border-[#E50914]/40 text-white'
                        : 'hover:bg-zinc-850 text-zinc-300'
                    }`}
                  >
                    <div className="flex items-center gap-2.5 min-w-0">
                      <div className="w-8 h-8 rounded bg-black/60 border border-zinc-800 flex items-center justify-center overflow-hidden shrink-0">
                        {(ch.logo || ch.logo_url || ch.tvg_logo) ? (
                          <img
                            src={ch.logo || ch.logo_url || ch.tvg_logo}
                            alt={ch.name}
                            className="w-full h-full object-contain p-0.5"
                            onError={(e) => { e.currentTarget.style.display = 'none'; }}
                          />
                        ) : (
                          <Tv className="w-3.5 h-3.5 text-zinc-500" />
                        )}
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          <span className="text-xs font-bold truncate group-hover:text-white">
                            {ch.name}
                          </span>
                        </div>
                        <span className="text-[10px] text-zinc-500 font-mono block">
                          CH {ch.channel_number || '-'} • {ch.group_title || 'General'}
                        </span>
                      </div>
                    </div>

                    {isActive ? (
                      <span className="w-2 h-2 rounded-full bg-[#E50914] animate-ping shrink-0" />
                    ) : (
                      <ChevronRight className="w-4 h-4 text-zinc-600 group-hover:text-zinc-300 shrink-0" />
                    )}
                  </button>
                );
              })
            )}
          </div>

          {/* Footer Zapping */}
          <div className="p-3 border-t border-zinc-800 text-[11px] text-zinc-500 text-center">
            {filteredChannels.length} canales disponibles en fibra On-Net
          </div>
        </aside>
      )}
    </div>
  );
}
