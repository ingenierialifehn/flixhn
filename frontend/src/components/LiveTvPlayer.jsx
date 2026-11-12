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
  AlertCircle,
  RefreshCw,
} from 'lucide-react';
import useTelemetryHeartbeat from '../hooks/useTelemetryHeartbeat';
import api from '../services/api';

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
  const controlsTimeoutRef = useRef(null);
  const currentPlaySessionRef = useRef(null);
  const currentDeviceIdRef = useRef('flixhn-web-client');

  // Actualizar canal actual cuando cambie la prop
  useEffect(() => {
    if (initialChannel && initialChannel.id !== currentChannel?.id) {
      setCurrentChannel(initialChannel);
    }
  }, [initialChannel]);

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

  // Intento de Reproducción con Sonido Activo y Captura de Bloqueo Autoplay
  const attemptPlay = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;

    // Iniciar con audio habilitado y volumen guardado
    video.volume = volume;
    video.muted = false;
    setIsMuted(false);

    const playPromise = video.play();
    if (playPromise !== undefined) {
      playPromise
        .then(() => {
          setIsPlaying(true);
          setIsLoading(false);
          setNeedsUnmutePrompt(false);
        })
        .catch((err) => {
          console.warn('Reproducción con audio bloqueada por el navegador:', err);
          // Si el navegador bloquea el autoplay con sonido la primera vez (NotAllowedError)
          if (err.name === 'NotAllowedError' || err.message?.includes('interact') || !video.muted) {
            video.muted = true;
            setIsMuted(true);
            video
              .play()
              .then(() => {
                setIsPlaying(true);
                setIsLoading(false);
                setNeedsUnmutePrompt(true);
              })
              .catch((e) => {
                console.warn('Autoplay silenciado también falló:', e);
                setIsLoading(false);
                setIsPlaying(false);
              });
          } else {
            setIsLoading(false);
            setIsPlaying(false);
          }
        });
    }
  }, [volume]);

  // Botón flotante: "Activar Sonido"
  const handleEnableSound = () => {
    const video = videoRef.current;
    if (!video) return;

    video.muted = false;
    const targetVol = volume > 0 ? volume : 1.0;
    video.volume = targetVol;
    setVolume(targetVol);
    setIsMuted(false);
    setNeedsUnmutePrompt(false);
    try {
      localStorage.setItem('livetv_volume', String(targetVol));
    } catch (e) {}
  };

  // Inicialización y Carga de Stream HLS Live con Negociación Emby
  const loadStream = useCallback(async (channelToPlay) => {
    if (!channelToPlay || !videoRef.current) return;

    setIsLoading(true);
    setStreamError(null);

    // 1. Liberar sintonizador / codificación activa previa
    if (currentPlaySessionRef.current) {
      releaseActiveEncoding(currentPlaySessionRef.current);
      currentPlaySessionRef.current = null;
    }

    // Destruir instancia Hls anterior si existe
    if (hlsRef.current) {
      hlsRef.current.destroy();
      hlsRef.current = null;
    }

    const video = videoRef.current;
    const targetChannelId = channelToPlay.emby_channel_id || channelToPlay.id || channelToPlay.tvg_id;
    const deviceId = currentDeviceIdRef.current;

    let finalHlsUrl = null;
    let playSessionId = null;

    // 2. Negociar PlaybackInfo con Emby a través del backend de FlixHN
    try {
      const res = await api.post('/livetv/playback-info', {
        channel_id: targetChannelId,
        device_id: deviceId,
        stream_url: channelToPlay.stream_url,
      });

      if (res.data && res.data.hls_url) {
        finalHlsUrl = res.data.hls_url;
        playSessionId = res.data.play_session_id;
      }
    } catch (err) {
      console.warn('LiveTvPlayer: Falló consulta PlaybackInfo en backend, probando Emby directo:', err);
    }

    // 3. Fallback directo a Emby API si el backend no pudo negociar
    if (!finalHlsUrl) {
      const serverHost = localStorage.getItem('media_server_url') || localStorage.getItem('emby_server_url') || 'http://45.4.87.126:6789';
      const apiKey = localStorage.getItem('emby_api_key') || localStorage.getItem('media_server_api_key') || '0fe0eb20e78f431d9fa474e05ab2d82f';
      const cleanBaseUrl = serverHost.replace(/\/emby\/?$/i, '');
      const embyChannelId = channelToPlay.emby_channel_id || targetChannelId;

      if (embyChannelId) {
        try {
          const deviceProfile = {
            Name: 'FlixHN Live Player',
            MaxStreamingBitrate: 20000000,
            DirectPlayProfiles: [
              {
                Container: 'ts,m3u8,mp4',
                Type: 'Video',
                VideoCodec: 'h264',
                AudioCodec: 'aac,mp3',
              },
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
            ContainerProfiles: [],
            CodecProfiles: [
              { Type: 'Video', Codec: 'h264' },
              {
                Type: 'Audio',
                Codec: 'aac',
                Conditions: [{ Condition: 'LessThanEqual', Property: 'AudioChannels', Value: '2' }],
              },
            ],
            ResponseProfiles: [],
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

          if (embyRes.ok) {
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
          console.warn('LiveTvPlayer: Error en negociación directa con Emby:', e);
        }
      }
    }

    // 4. Último fallback: usar stream_url directo del canal
    if (!finalHlsUrl) {
      finalHlsUrl = channelToPlay.stream_url;
    }

    if (!finalHlsUrl) {
      setStreamError('No se encontró una señal de transmisión válida para este canal.');
      setIsLoading(false);
      return;
    }

    currentPlaySessionRef.current = playSessionId;

    // 5. Configuración de HLS.js con tolerancia a paquetes en vivo y baja latencia desactivada
    const canPlayNativeHls = video.canPlayType('application/vnd.apple.mpegurl');
    const isHlsUrl = finalHlsUrl.includes('.m3u8') || finalHlsUrl.includes('output=hls') || finalHlsUrl.includes('/master') || finalHlsUrl.includes('/Videos/');

    if (Hls.isSupported() && (isHlsUrl || !canPlayNativeHls)) {
      const hls = new Hls({
        enableWorker: true,
        lowLatencyMode: false,
        backBufferLength: 30,
        maxBufferLength: 30,
        maxMaxBufferLength: 60,
        manifestLoadingTimeOut: 15000,
        manifestLoadingMaxRetry: 4,
        levelLoadingTimeOut: 15000,
        fragLoadingTimeOut: 20000,
        fragLoadingMaxRetry: 6,
      });

      hlsRef.current = hls;
      hls.loadSource(finalHlsUrl);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setIsLoading(false);
        attemptPlay();
      });

      hls.on(Hls.Events.ERROR, (event, data) => {
        if (data.fatal) {
          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              console.warn('HLS Live Network Error, intentando recuperar...', data);
              hls.startLoad();
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              console.warn('HLS Live Media Error, intentando recuperar decodificación...', data);
              hls.recoverMediaError();
              break;
            default:
              console.error('HLS Live Fatal Error:', data);
              setStreamError('No se pudo establecer la conexión con la señal en directo de este canal.');
              setIsLoading(false);
              hls.destroy();
              break;
          }
        } else {
          // Microcortes o saltos leves de señal IPTV: mantener reproduciendo
          console.warn('HLS Live aviso no fatal:', data.details);
        }
      });
    } else if (canPlayNativeHls) {
      video.src = finalHlsUrl;
      video.addEventListener('loadedmetadata', () => {
        setIsLoading(false);
        attemptPlay();
      }, { once: true });
      video.addEventListener('error', () => {
        setIsLoading(false);
        setStreamError('Error al decodificar la señal de video nativa.');
      }, { once: true });
    } else {
      video.src = finalHlsUrl;
      attemptPlay();
    }
  }, [releaseActiveEncoding, attemptPlay]);

  // Recargar stream al cambiar de canal y limpiar sintonizador al desmontar
  useEffect(() => {
    loadStream(currentChannel);

    return () => {
      if (currentPlaySessionRef.current) {
        releaseActiveEncoding(currentPlaySessionRef.current);
        currentPlaySessionRef.current = null;
      }
      if (hlsRef.current) {
        hlsRef.current.destroy();
        hlsRef.current = null;
      }
      if (controlsTimeoutRef.current) {
        clearTimeout(controlsTimeoutRef.current);
      }
    };
  }, [currentChannel, loadStream, releaseActiveEncoding]);

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
      videoRef.current.play().then(() => setIsPlaying(true)).catch(() => {});
    } else {
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

    if (!nextMuted) {
      setNeedsUnmutePrompt(false);
      const targetVol = volume === 0 ? 0.5 : volume;
      videoRef.current.volume = targetVol;
      setVolume(targetVol);
      try {
        localStorage.setItem('livetv_volume', String(targetVol));
      } catch (e) {}
    }
  };

  // Cambio de Volumen con slider
  const handleVolumeChange = (e) => {
    const val = parseFloat(e.target.value);
    setVolume(val);
    try {
      localStorage.setItem('livetv_volume', String(val));
    } catch (err) {}

    if (videoRef.current) {
      videoRef.current.volume = val;
      const nextMuted = val === 0;
      videoRef.current.muted = nextMuted;
      setIsMuted(nextMuted);
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

  return (
    <div
      ref={playerContainerRef}
      onMouseMove={handleMouseMove}
      className="fixed inset-0 z-50 bg-black text-white flex items-center justify-center overflow-hidden select-none font-sans"
    >
      {/* Elemento de Video */}
      <video
        ref={videoRef}
        onClick={togglePlay}
        playsInline
        className="w-full h-full object-contain cursor-pointer"
        onWaiting={() => setIsLoading(true)}
        onPlaying={() => {
          setIsLoading(false);
          setIsPlaying(true);
        }}
        onError={() => {
          setIsLoading(false);
          setStreamError('Error al decodificar la señal de video.');
        }}
      />

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
            <h3 className="text-lg font-bold text-white">Canal Temporalmente Fuera del Aire</h3>
            <p className="text-xs text-zinc-400">{streamError}</p>
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={() => loadStream(currentChannel)}
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
        <div className="flex items-center gap-4">
          {/* Play/Pause */}
          <button
            type="button"
            onClick={togglePlay}
            className="p-2 hover:bg-white/15 rounded-full transition-colors cursor-pointer"
          >
            {isPlaying ? <Pause className="w-6 h-6 fill-current" /> : <Play className="w-6 h-6 fill-current" />}
          </button>

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
