import React, { useState, useEffect, useRef } from 'react';
import { DownloadCloud, CheckCircle2, X } from 'lucide-react';
import api from '../api/axios';
import { getPlatformConfig } from './MovieCard';
import { resolvePosterUrl } from '../utils/media';

/**
 * Modal de Descarga On-Demand para el usuario en /browse.
 * Muestra exclusivamente una barra estética de "Descargando..." con progreso fluido (0% a 100%)
 * hasta completarse e iniciar de inmediato la reproducción automática.
 */
const OnDemandLoadingModal = ({ target, onReady, onCancel }) => {
  const [progress, setProgress] = useState(12);
  const [speed, setSpeed] = useState('42.8 MB/s');
  const [isCompleted, setIsCompleted] = useState(false);
  const isCancelledRef = useRef(false);
  const pollTimerRef = useRef(null);
  const simulatedTimerRef = useRef(null);

  const titleName = target?.name || target?.title || 'Contenido';
  const providerRaw = target?.provider || target?.source_platform || 'netflix';
  const platform = getPlatformConfig(providerRaw);

  const rawBanner = target?.backdrop_url || target?.poster_url || target?.image;
  const bannerUrl = resolvePosterUrl(rawBanner);

  useEffect(() => {
    isCancelledRef.current = false;
    let isMounted = true;
    let activeTaskId = null;
    let activeTitleId = null;

    // Incremento progresivo visual suave de respaldo para una experiencia fluida
    simulatedTimerRef.current = setInterval(() => {
      if (isCancelledRef.current) return;
      setProgress((prev) => {
        if (prev >= 96) return 96; // Espera al 100% real para la finalización
        if (prev < 40) return prev + Math.floor(Math.random() * 4) + 2;
        if (prev < 80) return prev + Math.floor(Math.random() * 3) + 1;
        return prev + 1;
      });
    }, 700);

    const finishAndPlay = (readyTitle) => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      if (simulatedTimerRef.current) clearInterval(simulatedTimerRef.current);
      setProgress(100);
      setIsCompleted(true);
      setSpeed('Descarga lista');
      setTimeout(() => {
        if (isMounted && !isCancelledRef.current && onReady) {
          onReady(readyTitle);
        }
      }, 450);
    };

    const pollStatus = (tId, ttlId) => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);

      pollTimerRef.current = setInterval(async () => {
        if (isCancelledRef.current) {
          clearInterval(pollTimerRef.current);
          return;
        }

        try {
          const q = new URLSearchParams();
          if (tId) q.append('task_id', tId);
          if (ttlId) q.append('title_id', ttlId);

          const res = await api.get(`/ott/on-demand-status?${q.toString()}`);
          if (isCancelledRef.current) return;

          const data = res.data;

          if (data?.ready && data?.title) {
            finishAndPlay(data.title);
            return;
          }

          if (data?.progress && data.progress > 0) {
            setProgress((prev) => Math.max(prev, Math.min(99, data.progress)));
          }

          if (data?.speed) {
            setSpeed(data.speed);
          }
        } catch (err) {
          // Continuar sin interrumpir la barra de descarga
        }
      }, 1000);
    };

    const startDownload = async () => {
      try {
        const rawType = String(target?.type || target?.media_type || '').toLowerCase();
        const titleLower = String(titleName || '').toLowerCase();
        const isSeries = ['series', 'serie', 'show', 'shows', 'tv', 'anime'].includes(rawType) ||
          /pokemon|pokémon|loki|stranger|hawkeye|daredevil|squid|invencible|invincible|jack ryan|serie|temporada|season/i.test(titleLower);
        const mediaType = isSeries ? 'series' : 'movie';

        const payload = {
          title: titleName,
          provider: platform.key,
          media_type: mediaType,
          poster_url: target?.poster_url || bannerUrl,
          backdrop_url: target?.backdrop_url || bannerUrl,
          overview: target?.description || target?.overview || '',
          year: target?.release_year || target?.year || new Date().getFullYear(),
          genre: target?.genre || 'Streaming',
        };

        const res = await api.post('/ott/on-demand-play', payload);
        if (!isMounted || isCancelledRef.current) return;

        if (res.data?.ready && res.data?.title) {
          finishAndPlay(res.data.title);
          return;
        }

        activeTaskId = res.data?.task_id;
        activeTitleId = res.data?.title_id;

        if (res.data?.progress) {
          setProgress((prev) => Math.max(prev, res.data.progress));
        }
        if (res.data?.speed) {
          setSpeed(res.data.speed);
        }

        pollStatus(activeTaskId, activeTitleId);
      } catch (err) {
        // En caso de reintento, mantener la barra activa y consultar estado por título
        pollStatus(null, target?.id);
      }
    };

    startDownload();

    return () => {
      isMounted = false;
      isCancelledRef.current = true;
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      if (simulatedTimerRef.current) clearInterval(simulatedTimerRef.current);
    };
  }, [titleName, platform.key, target?.type, target?.poster_url, target?.id, bannerUrl, onReady]);

  const handleCancel = () => {
    isCancelledRef.current = true;
    if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    if (simulatedTimerRef.current) clearInterval(simulatedTimerRef.current);
    if (onCancel) onCancel();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md animate-fadeIn select-none">
      {/* Fondo cinematográfico atenuado */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none opacity-20">
        <img
          src={bannerUrl}
          alt={titleName}
          className="w-full h-full object-cover object-center filter blur-2xl scale-110"
        />
        <div className="absolute inset-0 bg-gradient-to-t from-black via-black/80 to-black/70" />
      </div>

      {/* Tarjeta Central: Solo Barra de Descargando */}
      <div className="relative w-full max-w-md bg-[#141414] rounded-2xl border border-zinc-800 shadow-2xl p-6 sm:p-7 flex flex-col items-center text-center space-y-5 overflow-hidden">
        {/* Glow Superior */}
        <div
          className="absolute -top-20 left-1/2 -translate-x-1/2 w-56 h-56 rounded-full blur-3xl opacity-25 pointer-events-none"
          style={{ backgroundColor: platform.color || '#E50914' }}
        />

        {/* Botón de Cancelar en esquina */}
        <button
          onClick={handleCancel}
          className="absolute top-4 right-4 text-zinc-400 hover:text-white p-1 rounded-full hover:bg-zinc-800/80 transition-colors cursor-pointer"
          title="Cancelar"
          type="button"
        >
          <X className="w-4 h-4" />
        </button>

        {/* Ícono animado central */}
        <div className="relative w-20 h-20 flex items-center justify-center mt-1">
          {/* Anillo de giro */}
          <div
            className="absolute inset-0 rounded-full border-3 border-t-transparent animate-spin"
            style={{
              borderColor: `${platform.color || '#E50914'} transparent transparent transparent`,
              animationDuration: '1.1s',
            }}
          />
          <div className="absolute inset-2 rounded-full border border-white/10" />

          {isCompleted ? (
            <CheckCircle2 className="w-9 h-9 text-emerald-400 animate-bounce" />
          ) : (
            <DownloadCloud
              className="w-8 h-8 animate-pulse"
              style={{ color: platform.color || '#E50914' }}
            />
          )}
        </div>

        {/* Información del Título */}
        <div className="space-y-1.5 w-full px-2">
          <div className="flex items-center justify-center gap-2">
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${platform.badgeBg} ${platform.badgeBorder} text-white shadow-sm`}
            >
              <span>{platform.name}</span>
            </span>
            <span className="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 bg-zinc-800/90 px-2 py-0.5 rounded border border-white/5">
              {target?.type === 'series' ? 'Serie' : 'Película'}
            </span>
          </div>

          <h2 className="text-lg sm:text-xl font-bold text-white tracking-tight leading-snug line-clamp-2">
            {titleName}
          </h2>

          <p className="text-xs text-zinc-400">
            {isCompleted
              ? '¡Descarga completa! Iniciando reproducción...'
              : 'Descargando al servidor para ver ahora...'}
          </p>
        </div>

        {/* Barra de Descargando Central */}
        <div className="w-full space-y-3 bg-zinc-900/90 rounded-xl p-4 border border-zinc-800/80 shadow-inner">
          <div className="flex items-center justify-between text-xs font-semibold">
            <span className="text-zinc-300 flex items-center gap-1.5">
              <DownloadCloud className="w-3.5 h-3.5 text-[#E50914]" />
              <span>Descargando...</span>
            </span>
            <span className="font-mono font-bold text-white text-sm">
              {Math.round(progress)}%
            </span>
          </div>

          {/* Barra de Progreso Fluida */}
          <div className="w-full h-3 bg-zinc-800 rounded-full overflow-hidden p-0.5 ring-1 ring-white/10">
            <div
              className="h-full rounded-full transition-all duration-300 ease-out bg-gradient-to-r from-red-600 via-[#E50914] to-orange-500 shadow-[0_0_12px_rgba(229,9,20,0.6)]"
              style={{
                width: `${progress}%`,
                backgroundColor: platform.color || '#E50914',
              }}
            />
          </div>

          {/* Métricas: Velocidad y Disco */}
          <div className="flex items-center justify-between text-[11px] text-zinc-400 pt-1 border-t border-zinc-800/60 font-mono">
            <div className="flex items-center gap-1">
              <span className="text-zinc-500">Velocidad:</span>
              <span className="text-emerald-400 font-bold">{speed}</span>
            </div>
            <div className="flex items-center gap-1">
              <span className="text-zinc-500">Destino:</span>
              <span className="text-zinc-300 font-semibold">FlixHN Disk</span>
            </div>
          </div>
        </div>

        {/* Botón Cancelar */}
        <div className="pt-1 w-full">
          <button
            type="button"
            onClick={handleCancel}
            className="w-full py-2 px-4 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white text-xs font-bold transition-colors cursor-pointer border border-zinc-700"
          >
            Cancelar
          </button>
        </div>
      </div>
    </div>
  );
};

export default OnDemandLoadingModal;
