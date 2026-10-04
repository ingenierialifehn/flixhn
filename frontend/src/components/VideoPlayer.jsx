import React, { useRef, useEffect, useState, useCallback } from 'react';
import Hls from 'hls.js';
import {
  ArrowLeft,
  Play,
  Pause,
  RotateCcw,
  RotateCw,
  Volume2,
  VolumeX,
  Maximize,
  Minimize,
  SkipForward,
  Layers,
  MessageSquare,
  Gauge,
  X,
  Check,
  Tv,
} from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';

// Loader personalizado de HLS para inyectar obligatoriamente la api_key en los fragmentos .ts y listas m3u8
class EmbyHlsLoader extends (Hls.DefaultConfig?.loader || class {}) {
  load(context, config, callbacks) {
    if (context?.url) {
      const activeKey = window.__FLIXHN_ACTIVE_API_KEY__;
      if (activeKey && !context.url.includes('api_key=')) {
        const sep = context.url.includes('?') ? '&' : '?';
        context.url = `${context.url}${sep}api_key=${encodeURIComponent(activeKey)}`;
      }
    }
    super.load(context, config, callbacks);
  }
}

const VideoPlayer = ({ title: initialTitle, episode: initialEpisode, initialTime = 0, onBack, onClose }) => {
  const { activeProfile } = useAuth();
  const videoRef = useRef(null);
  const playerContainerRef = useRef(null);
  const hlsRef = useRef(null);
  const syncIntervalRef = useRef(null);
  const controlsTimeoutRef = useRef(null);
  const playSessionIdRef = useRef(null);
  const apiKeyRef = useRef('');

  const [title, setTitle] = useState(initialTitle);
  const [currentEpisode, setCurrentEpisode] = useState(initialEpisode);
  const [fullSeriesData, setFullSeriesData] = useState(null);

  const [isPlaying, setIsPlaying] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [buffered, setBuffered] = useState(0);
  const [volume, setVolume] = useState(1);
  const [isMuted, setIsMuted] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [showControls, setShowControls] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [playbackError, setPlaybackError] = useState(null);
  const [playbackMode, setPlaybackMode] = useState('direct'); // 'direct' | 'hls'

  // Menús desplegables de controles
  const [showEpisodeDrawer, setShowEpisodeDrawer] = useState(false);
  const [showAudioMenu, setShowAudioMenu] = useState(false);
  const [showSpeedMenu, setShowSpeedMenu] = useState(false);

  // Velocidad de reproducción (0.75x, 1x, 1.25x, 1.5x)
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  const speedOptions = [0.75, 1, 1.25, 1.5];

  // Pistas de Audio y Subtítulos
  const [audioTracks, setAudioTracks] = useState([]);
  const [currentAudioTrack, setCurrentAudioTrack] = useState(0);
  const [subtitleTracks, setSubtitleTracks] = useState([]);
  const [currentSubtitleTrack, setCurrentSubtitleTrack] = useState(-1); // -1 = Desactivado

  // Cargar información completa de temporadas y episodios si es una serie
  useEffect(() => {
    if (title?.type === 'series') {
      api.get(`/titles/${title.id}`)
        .then((res) => {
          if (res.data?.title) {
            setFullSeriesData(res.data.title);
          }
        })
        .catch((err) => console.warn('No se pudo cargar la lista completa de episodios:', err));
    }
  }, [title?.id, title?.type]);

  // Token de autorización del servidor para autorizar streams y fragmentos .ts (Punto 2)
  const apiKey = React.useMemo(() => {
    if (currentEpisode?.api_key) return currentEpisode.api_key;
    if (title?.api_key) return title.api_key;
    const raw = currentEpisode
      ? (currentEpisode.stream_path || currentEpisode.stream_url)
      : (title?.stream_url || title?.stream_path);
    if (raw) {
      try {
        const parsed = new URL(raw, window.location.origin);
        const key = parsed.searchParams.get('api_key');
        if (key) return key;
      } catch (e) {}
    }
    return localStorage.getItem('media_server_api_key') || localStorage.getItem('emby_api_key') || '0fe0eb20e78f431d9fa474e05ab2d82f';
  }, [currentEpisode, title]);

  useEffect(() => {
    if (apiKey) {
      apiKeyRef.current = apiKey;
      window.__FLIXHN_ACTIVE_API_KEY__ = apiKey;
    }
  }, [apiKey]);

  // Lista plana ordenada de episodios para navegación sencilla, excluyendo trailers o capítulos con 0 (Puntos 3 y 4)
  const allEpisodes = React.useMemo(() => {
    if (!fullSeriesData?.seasons) return currentEpisode ? [currentEpisode] : [];
    const list = [];
    const sortedSeasons = [...fullSeriesData.seasons].sort((a, b) => (a.season_number || 1) - (b.season_number || 1));
    sortedSeasons.forEach((s) => {
      const sNum = parseInt(s.season_number, 10);
      if (sNum === 0 || /^(especiales|trailers|temporada\s*0)/i.test(s.title || '')) return;
      if (s.episodes) {
        const sortedEps = [...s.episodes]
          .filter((ep) => {
            const epNum = parseInt(ep.episode_number, 10);
            const epTitle = (ep.title || '').trim();
            return epNum > 0 && !/^(0+|0\d+|cap[ií]tulo\s*0+|episodio\s*0+|trailer|especial)/i.test(epTitle);
          })
          .sort((a, b) => (a.episode_number || 1) - (b.episode_number || 1));

        sortedEps.forEach((ep) => {
          list.push({ ...ep, season_number: s.season_number || 1 });
        });
      }
    });
    return list;
  }, [fullSeriesData, currentEpisode]);

  // Encontrar el siguiente episodio si existe
  const nextEpisode = React.useMemo(() => {
    if (!currentEpisode || allEpisodes.length <= 1) return null;
    const currentIndex = allEpisodes.findIndex((e) => e.id === currentEpisode.id);
    if (currentIndex !== -1 && currentIndex < allEpisodes.length - 1) {
      return allEpisodes[currentIndex + 1];
    }
    return null;
  }, [allEpisodes, currentEpisode]);

  // Determinar la ruta base y parámetros del servidor de medios
  const rawStreamPath = currentEpisode
    ? (currentEpisode.stream_path || currentEpisode.stream_url)
    : (title?.stream_url || title?.stream_path);
  const defaultMediaBase = `${window.location.protocol}//${window.location.hostname}:7000/media`;
  const streamBase = import.meta.env.VITE_STREAM_URL || defaultMediaBase;

  // Extraer el ID de ítem del servidor de medios (Emby Item ID)
  const targetItemId = React.useMemo(() => {
    if (currentEpisode) {
      const epPath = currentEpisode.stream_path || currentEpisode.stream_url || '';
      const m = epPath.match(/\/Videos\/([a-zA-Z0-9_-]+)/i) || epPath.match(/\/Items\/([a-zA-Z0-9_-]+)/i);
      if (m) return m[1];
      if (currentEpisode.emby_id) return currentEpisode.emby_id;
    }
    if (title) {
      if (title.source_path) {
        const m = title.source_path.match(/remote:\/\/([a-zA-Z0-9_-]+)/i);
        if (m) return m[1];
      }
      const tPath = title.stream_path || title.stream_url || '';
      const m = tPath.match(/\/Videos\/([a-zA-Z0-9_-]+)/i) || tPath.match(/\/Items\/([a-zA-Z0-9_-]+)/i);
      if (m) return m[1];
    }
    return null;
  }, [title, currentEpisode]);

  const serverHost = React.useMemo(() => {
    if (rawStreamPath && rawStreamPath.startsWith('http')) {
      try {
        const parsed = new URL(rawStreamPath);
        return `${parsed.protocol}//${parsed.host}`;
      } catch (e) {}
    }
    return 'http://45.4.87.126:6789';
  }, [rawStreamPath]);

  const streamUrl = React.useMemo(() => {
    if (!rawStreamPath) return '';
    let url = rawStreamPath.startsWith('http')
      ? rawStreamPath
      : `${streamBase.replace(/\/$/, '')}/${rawStreamPath.replace(/^\//, '')}`;

    if (apiKey && !url.includes('api_key=')) {
      const sep = url.includes('?') ? '&' : '?';
      url = `${url}${sep}api_key=${encodeURIComponent(apiKey)}`;
    }

    return url;
  }, [rawStreamPath, streamBase, apiKey]);

  // Formateador de tiempo HH:MM:SS
  const formatTime = (timeInSeconds) => {
    if (isNaN(timeInSeconds) || timeInSeconds < 0) return '00:00';
    const hrs = Math.floor(timeInSeconds / 3600);
    const mins = Math.floor((timeInSeconds % 3600) / 60);
    const secs = Math.floor(timeInSeconds % 60);
    if (hrs > 0) {
      return `${hrs}:${mins < 10 ? '0' : ''}${mins}:${secs < 10 ? '0' : ''}${secs}`;
    }
    return `${mins < 10 ? '0' : ''}${mins}:${secs < 10 ? '0' : ''}${secs}`;
  };

  // Sincronizar progreso con el Backend
  const syncProgress = useCallback(async (currentSecs, totalSecs) => {
    if (!activeProfile || !title || !currentSecs || !totalSecs) return;
    try {
      await api.post('/playback/progress', {
        profile_id: activeProfile.id,
        title_id: title.id,
        episode_id: currentEpisode ? currentEpisode.id : null,
        current_seconds: Math.floor(currentSecs),
        duration_seconds: Math.floor(totalSecs),
      });
    } catch (e) {
      // Ignorar errores silenciosamente
    }
  }, [activeProfile, title, currentEpisode]);

  // Inicialización de Reproducción con Consulta previa a PlaybackInfo (Flujo Oficial Emby / HLS)
  useEffect(() => {
    const video = videoRef.current;
    if (!video || (!streamUrl && !targetItemId)) return;

    let resumeSeconds = initialTime || 0;
    let isCancelled = false;

    setIsLoading(true);

    // Función para limpiar la instancia previa de video y evitar peticiones duplicadas en conflicto
    const cleanupMediaInstance = () => {
      if (hlsRef.current) {
        try {
          hlsRef.current.stopLoad();
          hlsRef.current.destroy();
        } catch (e) {}
        hlsRef.current = null;
      }
      if (video) {
        try {
          video.pause();
          video.removeAttribute('src');
          video.load();
        } catch (e) {}
      }
    };

    cleanupMediaInstance();

    // Inicializador de HLS.js con loader autenticado e inyección de token en cada fragmento .ts
    const startHlsPlayback = (hlsSourceUrl) => {
      if (isCancelled || !videoRef.current) return;

      cleanupMediaInstance();
      setPlaybackMode('hls');

      if (Hls.isSupported()) {
        const hls = new Hls({
          capLevelToPlayerSize: true,
          autoStartLoad: true,
          loader: EmbyHlsLoader,
          fLoader: EmbyHlsLoader,
          pLoader: EmbyHlsLoader,
          xhrSetup: function (xhr, url) {
            const token = window.__FLIXHN_ACTIVE_API_KEY__ || apiKey;
            if (token) {
              xhr.setRequestHeader('X-Emby-Token', token);
              xhr.setRequestHeader('X-MediaBrowser-Token', token);
            }
          },
        });
        hlsRef.current = hls;

        hls.loadSource(hlsSourceUrl);
        hls.attachMedia(video);

        hls.on(Hls.Events.MANIFEST_PARSED, () => {
          if (isCancelled) return;
          setIsLoading(false);

          // Pistas de Audio HLS
          if (hls.audioTracks && hls.audioTracks.length > 0) {
            setAudioTracks(hls.audioTracks.map((t, idx) => ({
              id: idx,
              name: t.name || `Pista ${idx + 1}`,
              lang: t.lang || 'es',
            })));
            setCurrentAudioTrack(hls.audioTrack || 0);
          }

          // Subtítulos HLS
          if (hls.subtitleTracks && hls.subtitleTracks.length > 0) {
            setSubtitleTracks(hls.subtitleTracks.map((s, idx) => ({
              id: idx,
              name: s.name || `Subtítulo ${idx + 1}`,
            })));
          }

          if (resumeSeconds > 0) {
            video.currentTime = resumeSeconds;
          }

          video.playbackRate = playbackSpeed;
          video.play().then(() => {
            if (!isCancelled) setIsPlaying(true);
          }).catch(() => {});
        });

        hls.on(Hls.Events.ERROR, (event, data) => {
          if (data.fatal) {
            switch (data.type) {
              case Hls.ErrorTypes.NETWORK_ERROR:
                console.warn('Error de red HLS fatal, reintentando carga...', data);
                hls.startLoad();
                break;
              case Hls.ErrorTypes.MEDIA_ERROR:
                console.warn('Error de medios HLS fatal, recuperando...', data);
                hls.recoverMediaError();
                break;
              default:
                cleanupMediaInstance();
                setIsLoading(false);
                setPlaybackError('No se pudo procesar la transmisión HLS desde el servidor de medios.');
                break;
            }
          }
        });
      } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        // Soporte nativo HLS para Safari / iOS
        video.src = hlsSourceUrl;
        video.addEventListener('loadedmetadata', () => {
          if (isCancelled) return;
          setIsLoading(false);
          if (resumeSeconds > 0) video.currentTime = resumeSeconds;
          video.playbackRate = playbackSpeed;
          video.play().then(() => {
            if (!isCancelled) setIsPlaying(true);
          }).catch(() => {});
        }, { once: true });
      }
    };

    const initPlayerSession = async () => {
      // 1. Reanudar progreso previo
      if (resumeSeconds <= 0 && activeProfile && title) {
        try {
          const res = await api.get('/playback/progress', {
            params: {
              profile_id: activeProfile.id,
              title_id: title.id,
              episode_id: currentEpisode ? currentEpisode.id : null,
            },
          });
          if (!isCancelled && res.data?.resume_seconds && res.data.resume_seconds > 5) {
            resumeSeconds = res.data.resume_seconds;
          }
        } catch (e) {}
      }

      if (isCancelled || !videoRef.current) return;

      // 2. Consultar PlaybackInfo antes de montar el stream (vía Backend y fallback a Emby)
      let playInfo = null;
      try {
        const res = await api.get('/playback/info', {
          params: {
            title_id: title?.id,
            episode_id: currentEpisode?.id,
            item_id: targetItemId,
          },
        });
        if (res.data && (res.data.status === 'success' || res.data.status === 'fallback')) {
          playInfo = res.data;
        }
      } catch (err) {
        console.warn('No se pudo obtener PlaybackInfo del backend, intentando directo con servidor...', err);
      }

      const activeKey = playInfo?.api_key || apiKey;
      window.__FLIXHN_ACTIVE_API_KEY__ = activeKey;
      apiKeyRef.current = activeKey;

      if (!playInfo && targetItemId) {
        const cleanBaseUrl = serverHost.replace(/\/emby\/?$/i, '');
        try {
          const embyRes = await fetch(`${cleanBaseUrl}/emby/Items/${targetItemId}/PlaybackInfo?api_key=${activeKey}`, {
            headers: {
              'Accept': 'application/json',
              'X-Emby-Token': activeKey,
            },
          });
          if (embyRes.ok) {
            const embyData = await embyRes.json();
            const pSessionId = embyData.PlaySessionId;
            const ms = embyData.MediaSources?.[0];
            const msId = ms?.Id || targetItemId;
            const container = (ms?.Container || '').toLowerCase();
            const isDirect = ['mp4', 'm4v', 'webm'].includes(container);

            playInfo = {
              play_session_id: pSessionId,
              media_source_id: msId,
              container,
              is_direct_playable: isDirect,
              hls_url: `${cleanBaseUrl}/emby/Videos/${targetItemId}/master.m3u8?MediaSourceId=${msId}&DeviceId=flixhn-web-client&VideoCodec=h264&AudioCodec=aac&TranscodingMaxAudioChannels=2&SegmentContainer=ts&PlaySessionId=${pSessionId}&api_key=${activeKey}`,
              direct_url: `${cleanBaseUrl}/emby/Videos/${targetItemId}/stream?static=true&MediaSourceId=${msId}&DeviceId=flixhn-web-client&PlaySessionId=${pSessionId}&api_key=${activeKey}`,
              media_source: ms,
            };
          }
        } catch (embyErr) {
          console.warn('Error en consulta directa PlaybackInfo a Emby:', embyErr);
        }
      }

      if (isCancelled || !videoRef.current) return;

      // 3. Fallback de emergencia si no se pudo consultar PlaybackInfo
      if (!playInfo) {
        const cleanBase = serverHost.replace(/\/emby\/?$/i, '');
        const itmId = targetItemId || (streamUrl.match(/\/Videos\/([a-zA-Z0-9_-]+)/i)?.[1]);
        const fallbackSession = 'flixhn_' + Math.random().toString(36).substring(2, 10);
        playInfo = {
          play_session_id: fallbackSession,
          media_source_id: itmId,
          container: 'unknown',
          is_direct_playable: false,
          hls_url: `${cleanBase}/emby/Videos/${itmId}/master.m3u8?MediaSourceId=${itmId}&DeviceId=flixhn-web-client&VideoCodec=h264&AudioCodec=aac&TranscodingMaxAudioChannels=2&SegmentContainer=ts&api_key=${activeKey}`,
          direct_url: `${cleanBase}/emby/Videos/${itmId}/stream?static=true&MediaSourceId=${itmId}&DeviceId=flixhn-web-client&api_key=${activeKey}`,
        };
      }

      if (playInfo.play_session_id) {
        playSessionIdRef.current = playInfo.play_session_id;
      }

      setPlaybackError(null);

      // Si el servidor confirmó que el archivo está físicamente ausente (404)
      if (playInfo.is_available === false) {
        setIsLoading(false);
        setPlaybackError('Este archivo no se encuentra disponible temporalmente en el almacenamiento del servidor remoto.');
        return;
      }

      // Configurar pistas de audio y subtítulos disponibles desde el media_source
      if (playInfo.media_source?.MediaStreams) {
        const audios = playInfo.media_source.MediaStreams
          .filter((s) => s.Type === 'Audio')
          .map((s) => ({
            id: s.Index,
            name: s.DisplayTitle || `${s.Language || 'Pista'} (${s.Codec})`,
            lang: s.Language || 'es',
          }));
        if (audios.length > 0) setAudioTracks(audios);

        const subs = playInfo.media_source.MediaStreams
          .filter((s) => s.Type === 'Subtitle')
          .map((s) => ({
            id: s.Index,
            name: s.DisplayTitle || `${s.Language || 'Subtítulo'} (${s.Codec})`,
          }));
        if (subs.length > 0) setSubtitleTracks(subs);
      }

      // 4. Decidir estrategia de reproducción:
      // Si el formato es MP4/WebM nativo sin audio multicanal no soportado -> Reproducción directa
      // En caso contrario (MKV, TS, AC3, etc.) -> Iniciar directamente HLS transcodificado con PlaySessionId oficial
      if (playInfo.is_direct_playable && playInfo.direct_url) {
        setPlaybackMode('direct');
        video.src = playInfo.direct_url;

        const handleReady = () => {
          if (isCancelled) return;
          setIsLoading(false);
          if (resumeSeconds > 0) {
            video.currentTime = resumeSeconds;
          }
          video.playbackRate = playbackSpeed;
          video.play().then(() => {
            if (!isCancelled) setIsPlaying(true);
          }).catch(() => {});
        };

        const handleNativeError = (e) => {
          if (isCancelled) return;
          console.warn('Reproducción directa arrojó error, probando fallback /original o HLS...', e);

          // Si falló en reproducción directa, probar ruta /original correctamente construida
          try {
            const urlObj = new URL(playInfo.direct_url, window.location.origin);
            urlObj.pathname = urlObj.pathname.replace(/\/stream(?:\.mp4)?$/i, '/original');
            urlObj.searchParams.delete('static');
            urlObj.searchParams.delete('MediaSourceId');
            urlObj.searchParams.delete('DeviceId');
            urlObj.searchParams.delete('PlaySessionId');
            if (activeKey) {
              urlObj.searchParams.set('api_key', activeKey);
            }
            const originalUrl = urlObj.toString();

            cleanupMediaInstance();
            if (videoRef.current) {
              videoRef.current.src = originalUrl;
              videoRef.current.load();
              videoRef.current.play().then(() => {
                if (!isCancelled) setIsPlaying(true);
              }).catch(() => {
                startHlsPlayback(playInfo.hls_url);
              });
              return;
            }
          } catch (err) {}

          startHlsPlayback(playInfo.hls_url);
        };

        video.addEventListener('loadedmetadata', handleReady, { once: true });
        video.addEventListener('error', handleNativeError, { once: true });
        video.addEventListener('canplay', () => {
          if (!isCancelled) setIsLoading(false);
        }, { once: true });
        video.addEventListener('playing', () => {
          if (!isCancelled) {
            setIsLoading(false);
            setIsPlaying(true);
          }
        });
        video.addEventListener('waiting', () => {
          if (!isCancelled) setIsLoading(true);
        });
      } else {
        // MKV, TS o formatos que el navegador no soporta de forma nativa: HLS inmediato
        startHlsPlayback(playInfo.hls_url);
      }
    };

    initPlayerSession();

    // Sincronizar periódicamente cada 6 segundos
    syncIntervalRef.current = setInterval(() => {
      if (video && !video.paused && video.duration) {
        syncProgress(video.currentTime, video.duration);
      }
    }, 6000);

    return () => {
      isCancelled = true;
      if (syncIntervalRef.current) {
        clearInterval(syncIntervalRef.current);
        syncIntervalRef.current = null;
      }
      if (videoRef.current) {
        try {
          if (videoRef.current.currentTime > 0 && videoRef.current.duration) {
            syncProgress(videoRef.current.currentTime, videoRef.current.duration);
          }
        } catch (e) {}
      }
      cleanupMediaInstance();
    };
  }, [streamUrl, targetItemId, currentEpisode?.id, title?.id, initialTime, activeProfile, syncProgress, playbackSpeed, serverHost, apiKey]);

  // Actualización de tiempo y barra de progreso
  const handleTimeUpdate = () => {
    const video = videoRef.current;
    if (video) {
      setCurrentTime(video.currentTime);
      setDuration(video.duration || 0);

      if (video.buffered.length > 0) {
        const bufferedEnd = video.buffered.end(video.buffered.length - 1);
        setBuffered((bufferedEnd / video.duration) * 100);
      }
    }
  };

  // Alternar Reproducción / Pausa
  const togglePlay = () => {
    const video = videoRef.current;
    if (video) {
      if (video.paused) {
        video.play();
        setIsPlaying(true);
      } else {
        video.pause();
        setIsPlaying(false);
        syncProgress(video.currentTime, video.duration);
      }
    }
  };

  // Adelantar / Retroceder 10 segundos
  const handleSkip = (seconds) => {
    const video = videoRef.current;
    if (video) {
      video.currentTime = Math.max(0, Math.min(video.duration, video.currentTime + seconds));
    }
  };

  // Barra de progreso (Seek)
  const handleSeek = (e) => {
    const video = videoRef.current;
    if (video && duration) {
      const rect = e.currentTarget.getBoundingClientRect();
      const pos = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
      video.currentTime = pos * duration;
    }
  };

  // Control de Volumen
  const handleVolumeChange = (e) => {
    const newVol = parseFloat(e.target.value);
    setVolume(newVol);
    if (videoRef.current) {
      videoRef.current.volume = newVol;
      videoRef.current.muted = newVol === 0;
      setIsMuted(newVol === 0);
    }
  };

  const toggleMute = () => {
    if (videoRef.current) {
      videoRef.current.muted = !isMuted;
      setIsMuted(!isMuted);
    }
  };

  // Pantalla Completa
  const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
      playerContainerRef.current?.requestFullscreen();
      setIsFullscreen(true);
    } else {
      document.exitFullscreen();
      setIsFullscreen(false);
    }
  };

  // Cambio de Velocidad
  const handleSpeedChange = (speed) => {
    setPlaybackSpeed(speed);
    if (videoRef.current) {
      videoRef.current.playbackRate = speed;
    }
    setShowSpeedMenu(false);
  };

  // Cambio de Audio
  const handleAudioTrackChange = (trackId) => {
    setCurrentAudioTrack(trackId);
    if (hlsRef.current && hlsRef.current.audioTracks?.length > 0) {
      hlsRef.current.audioTrack = trackId;
    }
    setShowAudioMenu(false);
  };

  // Cambio de Subtítulo
  const handleSubtitleTrackChange = (subId) => {
    setCurrentSubtitleTrack(subId);
    if (hlsRef.current && hlsRef.current.subtitleTracks?.length > 0) {
      hlsRef.current.subtitleTrack = subId;
    }
    setShowAudioMenu(false);
  };

  // Cambiar a otro episodio directamente
  const handleSwitchEpisode = (ep) => {
    if (videoRef.current && duration > 0) {
      syncProgress(videoRef.current.currentTime, duration);
    }
    setCurrentEpisode(ep);
    setShowEpisodeDrawer(false);
  };

  // Ocultar controles automáticamente tras inactividad
  const handleMouseMove = () => {
    setShowControls(true);
    if (controlsTimeoutRef.current) clearTimeout(controlsTimeoutRef.current);
    controlsTimeoutRef.current = setTimeout(() => {
      if (isPlaying && !showEpisodeDrawer && !showAudioMenu && !showSpeedMenu) {
        setShowControls(false);
      }
    }, 4000);
  };

  // Salir limpiamente del reproductor sin dejar el audio sonando
  const handleClose = useCallback((e) => {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (syncIntervalRef.current) {
      clearInterval(syncIntervalRef.current);
      syncIntervalRef.current = null;
    }
    if (hlsRef.current) {
      try {
        hlsRef.current.stopLoad();
        hlsRef.current.destroy();
      } catch (err) {}
      hlsRef.current = null;
    }
    if (videoRef.current) {
      try {
        if (duration && videoRef.current.currentTime > 0) {
          syncProgress(videoRef.current.currentTime, duration);
        }
        videoRef.current.pause();
        videoRef.current.removeAttribute('src');
        videoRef.current.load();
      } catch (err) {}
    }
    if (typeof onClose === 'function') {
      onClose();
    } else if (typeof onBack === 'function') {
      onBack();
    } else if (typeof window !== 'undefined' && window.history) {
      window.history.back();
    }
  }, [duration, onBack, onClose, syncProgress]);

  // Atajos de teclado
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.code === 'Space') {
        e.preventDefault();
        togglePlay();
      } else if (e.code === 'KeyF') {
        toggleFullscreen();
      } else if (e.code === 'ArrowRight') {
        handleSkip(10);
      } else if (e.code === 'ArrowLeft') {
        handleSkip(-10);
      } else if (e.code === 'Escape') {
        if (showEpisodeDrawer) {
          setShowEpisodeDrawer(false);
        } else if (showAudioMenu) {
          setShowAudioMenu(false);
        } else if (showSpeedMenu) {
          setShowSpeedMenu(false);
        } else if (!document.fullscreenElement) {
          handleClose();
        }
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isPlaying, showEpisodeDrawer, showAudioMenu, showSpeedMenu, handleClose]);

  // Texto central: Título + T[X] E[Y] - Nombre del Episodio
  const episodeLabel = currentEpisode
    ? `T${currentEpisode.season_number || 1} E${currentEpisode.episode_number || 1} - ${currentEpisode.title}`
    : null;

  return (
    <div
      ref={playerContainerRef}
      onMouseMove={handleMouseMove}
      className="fixed inset-0 z-50 bg-black flex items-center justify-center select-none overflow-hidden"
    >
      {/* Elemento de Video HTML5 */}
      <video
        ref={videoRef}
        onClick={togglePlay}
        onTimeUpdate={handleTimeUpdate}
        className="w-full h-full object-contain cursor-pointer"
        playsInline
      />

      {/* Indicador de Carga */}
      {isLoading && !playbackError && (
        <div className="absolute inset-0 flex items-center justify-center bg-black/50 pointer-events-none z-30">
          <div className="w-16 h-16 border-4 border-[#E50914] border-t-transparent rounded-full animate-spin"></div>
        </div>
      )}

      {/* Mensaje de Error de Reproducción */}
      {playbackError && (
        <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/90 z-50 p-6 text-center">
          <div className="w-16 h-16 rounded-full bg-red-950/80 border border-red-600/50 flex items-center justify-center text-red-500 mb-4">
            <X className="w-8 h-8" />
          </div>
          <h2 className="text-xl font-bold text-white mb-2">Error de Reproducción</h2>
          <p className="text-sm text-neutral-400 max-w-md mb-6">{playbackError}</p>
          <button
            onClick={handleClose}
            className="px-6 py-2.5 bg-[#E50914] hover:bg-red-700 text-white font-medium rounded-md transition-all shadow-lg active:scale-95 cursor-pointer"
          >
            Volver al Catálogo
          </button>
        </div>
      )}

      {/* Botón Superior Izquierdo: Salir limpiamente del reproductor */}
      <div
        className={`absolute top-0 left-0 right-0 p-6 bg-gradient-to-b from-black/90 via-black/40 to-transparent flex items-center justify-between transition-opacity duration-300 z-40 ${
          showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'
        }`}
      >
        <button
          onClick={handleClose}
          className="w-11 h-11 rounded-full bg-black/60 hover:bg-neutral-800 text-white flex items-center justify-center transition-all shadow-md active:scale-95 cursor-pointer hover:text-[#E50914]"
          title="Salir del reproductor"
          type="button"
        >
          <ArrowLeft className="w-6 h-6 stroke-[2.5]" />
        </button>

        <div className="text-right">
          <span className="text-xs font-mono font-bold text-white/80 bg-neutral-900/80 px-3 py-1.5 rounded-full border border-neutral-700/80">
            {playbackMode === 'hls' ? 'FlixHN HLS Core' : 'FlixHN DirectPlay Core'}
          </span>
        </div>
      </div>

      {/* Barra Inferior Estilo Netflix (Imagen 3 de referencia) */}
      <div
        className={`absolute bottom-0 left-0 right-0 p-4 md:p-8 bg-gradient-to-t from-black/95 via-black/70 to-transparent transition-opacity duration-300 z-40 space-y-3 ${
          showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'
        }`}
      >
        {/* Barra de Progreso Superior Roja #E50914 con Tiempo al Extremo Derecho */}
        <div className="flex items-center gap-4">
          <div
            onClick={handleSeek}
            className="relative flex-1 h-1.5 hover:h-2.5 bg-neutral-700/80 rounded-full cursor-pointer transition-all group"
          >
            {/* Buffer precargado */}
            <div
              className="absolute top-0 left-0 bottom-0 bg-neutral-500/50 rounded-full"
              style={{ width: `${buffered}%` }}
            />
            {/* Tiempo reproducido en rojo #E50914 */}
            <div
              className="absolute top-0 left-0 bottom-0 bg-[#E50914] rounded-full relative"
              style={{ width: `${duration > 0 ? (currentTime / duration) * 100 : 0}%` }}
            >
              <div className="absolute right-0 top-1/2 -translate-y-1/2 w-4 h-4 bg-white rounded-full shadow-md scale-0 group-hover:scale-100 transition-transform" />
            </div>
          </div>

          {/* Tiempo restante / duración total al extremo derecho */}
          <div className="text-xs font-mono text-zinc-300 font-semibold whitespace-nowrap">
            <span>{formatTime(currentTime)}</span>
            <span className="mx-1 text-zinc-500">/</span>
            <span>{formatTime(duration)}</span>
          </div>
        </div>

        {/* Fila de Controles Inferiores */}
        <div className="flex items-center justify-between text-white pt-1">
          {/* LADO IZQUIERDO: Play/Pause, -10s, +10s, Volumen con slider */}
          <div className="flex items-center gap-3 md:gap-5">
            {/* Play / Pausa */}
            <button
              onClick={togglePlay}
              className="text-white hover:text-[#E50914] transition-colors p-1 cursor-pointer"
              title={isPlaying ? 'Pausa (Espacio)' : 'Reproducir (Espacio)'}
              type="button"
            >
              {isPlaying ? <Pause className="w-6 h-6 md:w-7 md:h-7" /> : <Play className="w-6 h-6 md:w-7 md:h-7 fill-white" />}
            </button>

            {/* Retroceder 10s */}
            <button
              onClick={() => handleSkip(-10)}
              className="text-white hover:text-[#E50914] transition-colors p-1 cursor-pointer"
              title="Retroceder 10 segundos"
              type="button"
            >
              <RotateCcw className="w-5 h-5 md:w-6 md:h-6" />
            </button>

            {/* Adelantar 10s */}
            <button
              onClick={() => handleSkip(10)}
              className="text-white hover:text-[#E50914] transition-colors p-1 cursor-pointer"
              title="Adelantar 10 segundos"
              type="button"
            >
              <RotateCw className="w-5 h-5 md:w-6 md:h-6" />
            </button>

            {/* Control de Volumen con Slider */}
            <div className="flex items-center gap-2 group/vol">
              <button
                onClick={toggleMute}
                className="text-white hover:text-[#E50914] transition-colors p-1 cursor-pointer"
                type="button"
              >
                {isMuted || volume === 0 ? (
                  <VolumeX className="w-5 h-5 md:w-6 md:h-6" />
                ) : (
                  <Volume2 className="w-5 h-5 md:w-6 md:h-6" />
                )}
              </button>
              <input
                type="range"
                min="0"
                max="1"
                step="0.05"
                value={isMuted ? 0 : volume}
                onChange={handleVolumeChange}
                className="w-16 md:w-24 h-1 accent-[#E50914] cursor-pointer"
              />
            </div>
          </div>

          {/* CENTRO: Título de la Serie + "T[X] E[Y] - Nombre del Episodio" */}
          <div className="hidden sm:flex flex-col items-center text-center px-4 max-w-md truncate">
            <span className="font-bold text-sm md:text-base text-zinc-100 truncate">
              {title?.name}
            </span>
            {episodeLabel && (
              <span className="text-xs text-zinc-400 font-medium truncate">
                {episodeLabel}
              </span>
            )}
          </div>

          {/* LADO DERECHO: Siguiente Episodio, Selector de Capítulos, Audio y Subtítulos, Velocidad, Fullscreen */}
          <div className="flex items-center gap-2 md:gap-4">
            {/* Botón 'Siguiente Episodio' (si aplica) */}
            {nextEpisode && (
              <button
                onClick={() => handleSwitchEpisode(nextEpisode)}
                className="text-white hover:text-[#E50914] transition-colors p-1.5 cursor-pointer"
                title={`Siguiente: ${nextEpisode.title}`}
                type="button"
              >
                <SkipForward className="w-5 h-5 md:w-6 md:h-6" />
              </button>
            )}

            {/* Botón Selector de Episodios (ícono de capas/episodios) */}
            {title?.type === 'series' && (
              <div className="relative">
                <button
                  onClick={() => {
                    setShowEpisodeDrawer(!showEpisodeDrawer);
                    setShowAudioMenu(false);
                    setShowSpeedMenu(false);
                  }}
                  className={`p-1.5 rounded transition-colors cursor-pointer ${
                    showEpisodeDrawer ? 'text-[#E50914]' : 'text-white hover:text-[#E50914]'
                  }`}
                  title="Episodios"
                  type="button"
                >
                  <Layers className="w-5 h-5 md:w-6 md:h-6" />
                </button>

                {/* Panel Flotante Oscuro con Lista de Capítulos */}
                {showEpisodeDrawer && (
                  <div className="absolute right-0 bottom-12 w-72 sm:w-80 max-h-96 overflow-y-auto bg-zinc-950/95 border border-zinc-800 rounded-xl shadow-2xl p-3 z-50 animate-fadeIn no-scrollbar">
                    <div className="flex items-center justify-between pb-2 mb-2 border-b border-zinc-800">
                      <span className="font-bold text-xs text-zinc-200 uppercase tracking-wider flex items-center gap-1.5">
                        <Tv className="w-4 h-4 text-[#E50914]" />
                        Episodios Disponibles
                      </span>
                      <button
                        onClick={() => setShowEpisodeDrawer(false)}
                        className="text-zinc-400 hover:text-white"
                        type="button"
                      >
                        <X className="w-4 h-4" />
                      </button>
                    </div>

                    <div className="space-y-1.5">
                      {allEpisodes.map((ep) => {
                        const isCurrent = currentEpisode?.id === ep.id;
                        return (
                          <div
                            key={ep.id}
                            onClick={() => handleSwitchEpisode(ep)}
                            className={`flex items-center gap-3 p-2 rounded-lg cursor-pointer transition-colors text-xs ${
                              isCurrent
                                ? 'bg-[#E50914]/20 border border-[#E50914]/50 text-white font-bold'
                                : 'hover:bg-zinc-800/80 text-zinc-300'
                            }`}
                          >
                            <span className="w-5 text-center font-mono text-zinc-500">
                              {ep.episode_number}
                            </span>
                            <div className="flex-1 truncate">
                              <p className="truncate">{ep.title}</p>
                              <span className="text-[10px] text-zinc-500">
                                {formatTime(ep.duration_seconds)}
                              </span>
                            </div>
                            {isCurrent && (
                              <span className="text-[10px] text-[#E50914] uppercase tracking-wider font-bold">
                                Reproduciendo
                              </span>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* Botón de Audio y Subtítulos (ícono MessageSquare) */}
            <div className="relative">
              <button
                onClick={() => {
                  setShowAudioMenu(!showAudioMenu);
                  setShowEpisodeDrawer(false);
                  setShowSpeedMenu(false);
                }}
                className={`p-1.5 rounded transition-colors cursor-pointer ${
                  showAudioMenu ? 'text-[#E50914]' : 'text-white hover:text-[#E50914]'
                }`}
                title="Audio y Subtítulos"
                type="button"
              >
                <MessageSquare className="w-5 h-5 md:w-6 md:h-6" />
              </button>

              {/* Menú Desplegable Oscuro de Audio y Subtítulos */}
              {showAudioMenu && (
                <div className="absolute right-0 bottom-12 w-64 bg-zinc-950/95 border border-zinc-800 rounded-xl shadow-2xl p-3 z-50 text-xs animate-fadeIn space-y-3">
                  <div>
                    <h4 className="font-bold text-zinc-400 pb-1 mb-1 border-b border-zinc-800 uppercase tracking-wider text-[10px]">
                      Pistas de Audio
                    </h4>
                    <div className="space-y-1">
                      {audioTracks.map((track) => (
                        <button
                          key={track.id}
                          onClick={() => handleAudioTrackChange(track.id)}
                          className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded text-left transition-colors cursor-pointer ${
                            currentAudioTrack === track.id
                              ? 'bg-[#E50914]/20 text-[#E50914] font-bold'
                              : 'text-zinc-300 hover:bg-zinc-800'
                          }`}
                          type="button"
                        >
                          <span>{track.name}</span>
                          {currentAudioTrack === track.id && <Check className="w-3.5 h-3.5" />}
                        </button>
                      ))}
                    </div>
                  </div>

                  <div>
                    <h4 className="font-bold text-zinc-400 pb-1 mb-1 border-b border-zinc-800 uppercase tracking-wider text-[10px]">
                      Subtítulos
                    </h4>
                    <div className="space-y-1">
                      <button
                        onClick={() => handleSubtitleTrackChange(-1)}
                        className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded text-left transition-colors cursor-pointer ${
                          currentSubtitleTrack === -1
                            ? 'bg-[#E50914]/20 text-[#E50914] font-bold'
                            : 'text-zinc-300 hover:bg-zinc-800'
                        }`}
                        type="button"
                      >
                        <span>Desactivados</span>
                        {currentSubtitleTrack === -1 && <Check className="w-3.5 h-3.5" />}
                      </button>
                      {subtitleTracks.map((sub) => (
                        <button
                          key={sub.id}
                          onClick={() => handleSubtitleTrackChange(sub.id)}
                          className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded text-left transition-colors cursor-pointer ${
                            currentSubtitleTrack === sub.id
                              ? 'bg-[#E50914]/20 text-[#E50914] font-bold'
                              : 'text-zinc-300 hover:bg-zinc-800'
                          }`}
                          type="button"
                        >
                          <span>{sub.name}</span>
                          {currentSubtitleTrack === sub.id && <Check className="w-3.5 h-3.5" />}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Velocidad de Reproducción (0.75x, 1x, 1.25x, 1.5x) */}
            <div className="relative">
              <button
                onClick={() => {
                  setShowSpeedMenu(!showSpeedMenu);
                  setShowEpisodeDrawer(false);
                  setShowAudioMenu(false);
                }}
                className={`p-1.5 rounded transition-colors text-xs font-mono font-bold flex items-center gap-1 cursor-pointer ${
                  showSpeedMenu ? 'text-[#E50914]' : 'text-white hover:text-[#E50914]'
                }`}
                title="Velocidad de reproducción"
                type="button"
              >
                <Gauge className="w-4 h-4 md:w-5 md:h-5" />
                <span>{playbackSpeed}x</span>
              </button>

              {showSpeedMenu && (
                <div className="absolute right-0 bottom-12 w-32 bg-zinc-950/95 border border-zinc-800 rounded-xl shadow-2xl p-1.5 z-50 text-xs animate-fadeIn">
                  <div className="px-2 py-1 font-bold text-zinc-400 border-b border-zinc-800 text-[10px] uppercase">
                    Velocidad
                  </div>
                  {speedOptions.map((speed) => (
                    <button
                      key={speed}
                      onClick={() => handleSpeedChange(speed)}
                      className={`w-full flex items-center justify-between px-2 py-1.5 rounded text-left transition-colors cursor-pointer ${
                        playbackSpeed === speed
                          ? 'text-[#E50914] font-bold bg-[#E50914]/15'
                          : 'text-zinc-300 hover:bg-zinc-800'
                      }`}
                      type="button"
                    >
                      <span>{speed === 1 ? 'Normal (1x)' : `${speed}x`}</span>
                      {playbackSpeed === speed && <Check className="w-3 h-3" />}
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/* Pantalla Completa */}
            <button
              onClick={toggleFullscreen}
              className="text-white hover:text-[#E50914] transition-colors p-1.5 cursor-pointer"
              title="Pantalla completa (F)"
              type="button"
            >
              {isFullscreen ? <Minimize className="w-5 h-5 md:w-6 md:h-6" /> : <Maximize className="w-5 h-5 md:w-6 md:h-6" />}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default VideoPlayer;
