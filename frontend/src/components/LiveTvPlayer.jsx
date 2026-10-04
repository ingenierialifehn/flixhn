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
  Radio,
  Tv,
  X,
  Layers,
  Search,
  ChevronRight,
  AlertCircle,
  Loader2,
  RefreshCw,
} from 'lucide-react';

export default function LiveTvPlayer({
  channel: initialChannel,
  channels = [],
  onClose,
  onSelectChannel,
}) {
  const [currentChannel, setCurrentChannel] = useState(initialChannel);
  const [isPlaying, setIsPlaying] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [volume, setVolume] = useState(1);
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

  // Actualizar canal actual cuando cambie la prop
  useEffect(() => {
    if (initialChannel && initialChannel.id !== currentChannel?.id) {
      setCurrentChannel(initialChannel);
    }
  }, [initialChannel]);

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

  // Inicialización y Carga de Stream HLS Live
  const loadStream = useCallback((channelToPlay) => {
    if (!channelToPlay?.stream_url || !videoRef.current) return;

    setIsLoading(true);
    setStreamError(null);

    // Destruir instancia Hls anterior si existe
    if (hlsRef.current) {
      hlsRef.current.destroy();
      hlsRef.current = null;
    }

    const video = videoRef.current;
    const streamUrl = channelToPlay.stream_url;

    // Detectar si el navegador tiene soporte nativo HLS (Safari/iOS)
    const canPlayNativeHls = video.canPlayType('application/vnd.apple.mpegurl');
    const isHlsUrl = streamUrl.includes('.m3u8') || streamUrl.includes('output=hls');

    if (Hls.isSupported() && isHlsUrl) {
      const hls = new Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 60,
        liveSyncDurationCount: 3,
        liveMaxLatencyDurationCount: 6,
        maxBufferLength: 10,
        maxMaxBufferLength: 20,
        maxBufferSize: 30 * 1000 * 1000,
        fragLoadingTimeOut: 15000,
        manifestLoadingTimeOut: 15000,
      });

      hlsRef.current = hls;
      hls.loadSource(streamUrl);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setIsLoading(false);
        video.play().then(() => setIsPlaying(true)).catch((err) => {
          console.warn('Reproducción automática bloqueada por navegador:', err);
          setIsPlaying(false);
        });
      });

      hls.on(Hls.Events.ERROR, (event, data) => {
        if (data.fatal) {
          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              console.warn('HLS Live Network Error, intentando recuperar...', data);
              hls.startLoad();
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              console.warn('HLS Live Media Error, intentando recuperar...', data);
              hls.recoverMediaError();
              break;
            default:
              console.error('HLS Live Fatal Error:', data);
              setStreamError('No se pudo establecer la conexión con la señal en directo de este canal.');
              setIsLoading(false);
              hls.destroy();
              break;
          }
        }
      });
    } else if (canPlayNativeHls || !isHlsUrl) {
      video.src = streamUrl;
      video.play().then(() => {
        setIsLoading(false);
        setIsPlaying(true);
      }).catch(() => {
        setIsLoading(false);
      });
    } else {
      setStreamError('El formato de transmisión de este canal no es compatible directamente con este navegador.');
      setIsLoading(false);
    }
  }, []);

  // Recargar stream al cambiar de canal
  useEffect(() => {
    loadStream(currentChannel);

    return () => {
      if (hlsRef.current) {
        hlsRef.current.destroy();
        hlsRef.current = null;
      }
      if (controlsTimeoutRef.current) {
        clearTimeout(controlsTimeoutRef.current);
      }
    };
  }, [currentChannel, loadStream]);

  // Play / Pausa
  const togglePlay = () => {
    if (!videoRef.current) return;
    if (videoRef.current.paused) {
      videoRef.current.play();
      setIsPlaying(true);
    } else {
      videoRef.current.pause();
      setIsPlaying(false);
    }
  };

  // Mute
  const toggleMute = () => {
    if (!videoRef.current) return;
    const nextMuted = !isMuted;
    videoRef.current.muted = nextMuted;
    setIsMuted(nextMuted);
    if (!nextMuted && volume === 0) {
      setVolume(0.5);
      videoRef.current.volume = 0.5;
    }
  };

  // Cambio de Volumen
  const handleVolumeChange = (e) => {
    const val = parseFloat(e.target.value);
    setVolume(val);
    if (videoRef.current) {
      videoRef.current.volume = val;
      videoRef.current.muted = val === 0;
      setIsMuted(val === 0);
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
            {currentChannel?.logo_url ? (
              <img
                src={currentChannel.logo_url}
                alt={currentChannel.name}
                className="w-full h-full object-contain p-1"
                onError={(e) => { e.target.style.display = 'none'; }}
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
              className="p-1 text-zinc-400 hover:text-white rounded"
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
                        {ch.logo_url ? (
                          <img
                            src={ch.logo_url}
                            alt={ch.name}
                            className="w-full h-full object-contain p-0.5"
                            onError={(e) => { e.target.style.display = 'none'; }}
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
