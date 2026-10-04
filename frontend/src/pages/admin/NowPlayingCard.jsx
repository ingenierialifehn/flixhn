import React, { useState } from 'react';
import {
  Play,
  Pause,
  Square,
  Tv,
  Laptop,
  Smartphone,
  Radio,
  Wifi,
  Film,
  Zap,
  Volume2,
} from 'lucide-react';
import api from '../../services/api';

const formatSeconds = (sec) => {
  if (!sec || isNaN(sec)) return '00:00';
  const hours = Math.floor(sec / 3600);
  const minutes = Math.floor((sec % 3600) / 60);
  const seconds = Math.floor(sec % 60);
  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
};

export const NowPlayingCard = ({ session, onSessionStopped }) => {
  const [isPaused, setIsPaused] = useState(false);
  const [stopping, setStopping] = useState(false);

  const handleStopSession = async () => {
    if (!window.confirm(`¿Deseas desconectar y detener la sesión activa de ${session.subscriber_name}?`)) {
      return;
    }
    setStopping(true);
    try {
      await api.post(`/admin/sessions/${session.id}/stop`);
      if (onSessionStopped) onSessionStopped(session.id);
    } catch (err) {
      console.error('Error deteniendo sesión:', err);
      // Fallback local removal
      if (onSessionStopped) onSessionStopped(session.id);
    } finally {
      setStopping(false);
    }
  };

  const getDeviceIcon = (deviceStr = '') => {
    const lower = deviceStr.toLowerCase();
    if (lower.includes('tv') || lower.includes('tizen') || lower.includes('roku')) {
      return <Tv className="w-4 h-4 text-red-500 dark:text-red-400" />;
    }
    if (lower.includes('phone') || lower.includes('android') || lower.includes('ios')) {
      return <Smartphone className="w-4 h-4 text-blue-500 dark:text-blue-400" />;
    }
    return <Laptop className="w-4 h-4 text-emerald-500 dark:text-emerald-400" />;
  };

  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 hover:border-zinc-700/80 rounded-xl overflow-hidden shadow-none transition-all duration-300 group">
      <div className="flex flex-col md:flex-row items-stretch">
        {/* Póster / Miniatura del contenido con barra de progreso */}
        <div className="relative md:w-56 lg:w-64 h-48 md:h-auto bg-gray-100 dark:bg-zinc-900 flex-shrink-0 overflow-hidden">
          {session.poster_url ? (
            <img
              src={session.poster_url}
              alt={session.title_name}
              className="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-500"
              onError={(e) => {
                e.target.onerror = null;
                e.target.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400" viewBox="0 0 300 400"><rect width="100%" height="100%" fill="%23222"/><text x="50%" y="50%" fill="%23777" font-family="sans-serif" font-size="14" text-anchor="middle">FlixHN Stream</text></svg>';
              }}
            />
          ) : (
            <div className="w-full h-full flex flex-col items-center justify-center p-4 text-gray-400 dark:text-zinc-500 bg-gray-100 dark:bg-zinc-900">
              <Film className="w-10 h-10 mb-2 text-gray-400 dark:text-zinc-600" />
              <span className="text-xs font-mono text-center">FlixHN Media</span>
            </div>
          )}

          {/* Estado de reproducción (badge flotante) */}
          <div className="absolute top-2.5 left-2.5">
            <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider backdrop-blur-md shadow-md ${
              isPaused
                ? 'bg-amber-950/80 text-amber-300 border border-amber-700/60'
                : 'bg-black/75 text-emerald-400 border border-emerald-500/40'
            }`}>
              <span className={`w-1.5 h-1.5 rounded-full ${isPaused ? 'bg-amber-400' : 'bg-emerald-400 animate-ping'}`} />
              {isPaused ? 'En Pausa' : 'Transmitiendo'}
            </span>
          </div>

          {/* Barra de Progreso Roja en la parte inferior de la imagen */}
          <div className="absolute bottom-0 left-0 right-0 h-1.5 bg-gray-200 dark:bg-zinc-800">
            <div
              className="h-full bg-[#E50914] transition-all duration-300 shadow-[0_0_8px_rgba(229,9,20,0.8)]"
              style={{ width: `${Math.max(5, Math.min(100, session.progress_percent || 0))}%` }}
            />
          </div>
        </div>

        {/* Detalles de la Sesión y Telemetría Técnica */}
        <div className="flex-1 p-5 flex flex-col justify-between space-y-4">
          {/* Fila 1: Título de Contenido y Datos del Abonado */}
          <div className="space-y-1.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 className="text-lg md:text-xl font-bold text-gray-900 dark:text-white tracking-wide group-hover:text-[#E50914] transition-colors">
                  {session.title_name}
                </h3>
                {session.episode_title && (
                  <p className="text-xs text-gray-500 dark:text-zinc-400 font-medium">
                    {session.episode_title}
                  </p>
                )}
              </div>

              {/* Botones de Control de Sesión */}
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setIsPaused(!isPaused)}
                  title={isPaused ? 'Reanudar sesión' : 'Pausar sesión'}
                  className="flex items-center gap-1.5 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 text-gray-700 dark:text-zinc-200 hover:text-gray-900 dark:hover:text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors border border-gray-200 dark:border-zinc-700/60 shadow-sm"
                >
                  {isPaused ? (
                    <>
                      <Play className="w-3.5 h-3.5 fill-current text-emerald-600 dark:text-emerald-400" />
                      <span>Reanudar</span>
                    </>
                  ) : (
                    <>
                      <Pause className="w-3.5 h-3.5 fill-current text-amber-500 dark:text-amber-400" />
                      <span>Pausar</span>
                    </>
                  )}
                </button>

                <button
                  onClick={handleStopSession}
                  disabled={stopping}
                  title="Detener sesión de cliente"
                  className="flex items-center gap-1.5 bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/80 text-red-700 dark:text-red-200 hover:text-red-900 dark:hover:text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors border border-red-200 dark:border-red-800/80 disabled:opacity-50 shadow-sm"
                >
                  <Square className="w-3 h-3 fill-current text-[#E50914]" />
                  <span>{stopping ? 'Deteniendo...' : 'Detener'}</span>
                </button>
              </div>
            </div>

            {/* Suscriptor y Tiempo */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-zinc-300">
              <span className="flex items-center gap-1.5 font-medium">
                <span className="w-2 h-2 rounded-full bg-[#E50914]" />
                Abonado: <strong className="text-gray-900 dark:text-white font-semibold">{session.subscriber_name}</strong>
              </span>
              <span className="text-gray-300 dark:text-zinc-600">•</span>
              <span className="font-mono text-gray-500 dark:text-zinc-400">
                Progreso: {formatSeconds(session.current_seconds)} / {formatSeconds(session.duration_seconds)} ({session.progress_percent}%)
              </span>
            </div>
          </div>

          {/* Fila 2: Dispositivo Cliente e IP Local del Abonado */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
            <div className="bg-gray-50 dark:bg-zinc-900/90 border border-gray-200 dark:border-zinc-800/80 px-3 py-2 rounded-lg flex items-center gap-2.5">
              {getDeviceIcon(session.client_device)}
              <div className="truncate">
                <p className="text-[10px] text-gray-400 dark:text-zinc-500 font-semibold uppercase">Dispositivo Cliente</p>
                <p className="font-medium text-gray-800 dark:text-zinc-200 truncate">{session.client_device}</p>
              </div>
            </div>

            <div className="bg-gray-50 dark:bg-zinc-900/90 border border-gray-200 dark:border-zinc-800/80 px-3 py-2 rounded-lg flex items-center gap-2.5">
              <Wifi className="w-4 h-4 text-emerald-500 dark:text-emerald-400" />
              <div>
                <p className="text-[10px] text-gray-400 dark:text-zinc-500 font-semibold uppercase">IP Local On-Net</p>
                <p className="font-mono font-semibold text-gray-800 dark:text-zinc-200">{session.client_ip}</p>
              </div>
            </div>
          </div>

          {/* Fila 3: Parámetros Técnicos de Transmisión (HLS / Bitrate / Codecs) */}
          <div className="flex flex-wrap items-center gap-2 pt-1 border-t border-gray-200 dark:border-zinc-800/60 text-[11px]">
            {/* Stream Type */}
            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700/80 text-gray-700 dark:text-zinc-300 font-medium">
              <Zap className="w-3 h-3 text-amber-500 dark:text-amber-400" />
              Stream: <strong className="text-gray-900 dark:text-white">{session.stream_type}</strong>
            </span>

            {/* Bitrate */}
            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700/80 text-gray-700 dark:text-zinc-300 font-mono">
              <Radio className="w-3 h-3 text-emerald-500 dark:text-emerald-400" />
              Tasa: <strong className="text-gray-900 dark:text-white font-semibold">{session.bitrate}</strong>
            </span>

            {/* Video Codec */}
            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700/80 text-gray-700 dark:text-zinc-300 font-mono">
              <Film className="w-3 h-3 text-blue-500 dark:text-blue-400" />
              Video: <strong className="text-gray-900 dark:text-white font-medium">{session.video_codec}</strong>
            </span>

            {/* Audio Codec */}
            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700/80 text-gray-700 dark:text-zinc-300 font-mono">
              <Volume2 className="w-3 h-3 text-purple-500 dark:text-purple-400" />
              Audio: <strong className="text-gray-900 dark:text-white font-medium">{session.audio_codec}</strong>
            </span>
          </div>
        </div>
      </div>
    </div>
  );
};

export const NowPlayingSection = ({ sessions = [], activeStreams, onSessionStopped }) => {
  const currentSessions = activeStreams !== undefined ? activeStreams : sessions;

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <div className="p-1.5 bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-900/60 rounded-md text-[#E50914]">
            <Radio className="w-4 h-4 animate-pulse" />
          </div>
          <div>
            <h2 className="text-base md:text-lg font-bold text-gray-900 dark:text-white tracking-wide flex items-center gap-2">
              En Reproducción Activa (Now Playing)
              {currentSessions.length > 0 && (
                <span className="text-xs font-mono bg-red-50 dark:bg-red-600/30 text-red-600 dark:text-red-400 px-2 py-0.5 rounded-full border border-red-200 dark:border-red-800">
                  {currentSessions.length} {currentSessions.length === 1 ? 'sesión' : 'sesiones'}
                </span>
              )}
            </h2>
            <p className="text-xs text-gray-500 dark:text-zinc-400">
              Monitoreo en tiempo real de transmisiones concurrentes en la red local
            </p>
          </div>
        </div>
      </div>

      {/* Renderizado de sesiones o estado vacío elegante */}
      {(currentSessions || []).length > 0 ? (
        <div className="space-y-4">
          {(currentSessions || []).map((session) => (
            <NowPlayingCard
              key={session?.id}
              session={session}
              onSessionStopped={onSessionStopped}
            />
          ))}
        </div>
      ) : (
        <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-8 text-center space-y-3 shadow-none transition-colors">
          <div className="w-12 h-12 rounded-full bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 text-[#E50914] mx-auto flex items-center justify-center">
            <Radio className="w-6 h-6 stroke-[1.5]" />
          </div>
          <div className="space-y-1.5 max-w-lg mx-auto">
            <h4 className="text-sm md:text-base font-semibold text-zinc-900 dark:text-zinc-200">
              No hay transmisiones activas en este momento
            </h4>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              No hay transmisiones activas en este momento. La telemetría HLS y control de sesión se mostrarán en tiempo real cuando un abonado inicie una reproducción.
            </p>
          </div>
        </div>
      )}
    </div>
  );
};

export default NowPlayingSection;
