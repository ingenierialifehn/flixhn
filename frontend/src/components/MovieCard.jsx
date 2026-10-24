import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { Play, X } from 'lucide-react';
import HoverPreviewCard from './HoverPreviewCard';
import { resolvePosterUrl, handlePosterError } from '../utils/media';

/**
 * Configuración visual y branding de plataformas OTT y catálogo local FlixHN
 */
export const getPlatformConfig = (providerRaw) => {
  const p = String(providerRaw || 'local').toLowerCase().trim();

  if (p === 'netflix') {
    return {
      key: 'netflix',
      name: 'Netflix',
      shortName: 'Netflix',
      color: '#E50914',
      badgeBg: 'bg-[#E50914]',
      badgeBorder: 'border-red-400/40',
      badgeShadow: 'shadow-red-950/60',
      pillBg: 'bg-red-600/20 text-red-300 border-red-500/40 hover:bg-red-600/30',
      activePill: 'bg-[#E50914] text-white border-red-400 shadow-lg shadow-red-950/60 ring-2 ring-red-400/30',
      glow: 'from-red-600/20 via-transparent to-transparent',
      dotColor: '#E50914',
      isLocal: false,
    };
  }

  if (p === 'disney' || p === 'disney_plus' || p === 'disney+') {
    return {
      key: 'disney',
      name: 'Disney+',
      shortName: 'Disney+',
      color: '#0063E5',
      badgeBg: 'bg-[#0063E5]',
      badgeBorder: 'border-blue-400/40',
      badgeShadow: 'shadow-blue-950/60',
      pillBg: 'bg-blue-600/20 text-blue-300 border-blue-500/40 hover:bg-blue-600/30',
      activePill: 'bg-[#0063E5] text-white border-blue-400 shadow-lg shadow-blue-950/60 ring-2 ring-blue-400/30',
      glow: 'from-blue-600/20 via-transparent to-transparent',
      dotColor: '#0063E5',
      isLocal: false,
    };
  }

  if (p === 'hbo_max' || p === 'max' || p === 'hbo') {
    return {
      key: 'hbo_max',
      name: 'HBO Max',
      shortName: 'HBO Max',
      color: '#5822b4',
      badgeBg: 'bg-[#5822b4]',
      badgeBorder: 'border-purple-400/40',
      badgeShadow: 'shadow-purple-950/60',
      pillBg: 'bg-purple-600/20 text-purple-300 border-purple-500/40 hover:bg-purple-600/30',
      activePill: 'bg-[#5822b4] text-white border-purple-400 shadow-lg shadow-purple-950/60 ring-2 ring-purple-400/30',
      glow: 'from-purple-600/20 via-transparent to-transparent',
      dotColor: '#8B5CF6',
      isLocal: false,
    };
  }

  if (p === 'prime' || p === 'amazon_prime' || p === 'amazon') {
    return {
      key: 'prime',
      name: 'Prime Video',
      shortName: 'Prime',
      color: '#00A8E1',
      badgeBg: 'bg-[#00A8E1]',
      badgeBorder: 'border-sky-400/40',
      badgeShadow: 'shadow-sky-950/60',
      pillBg: 'bg-sky-600/20 text-sky-300 border-sky-500/40 hover:bg-sky-600/30',
      activePill: 'bg-[#00A8E1] text-white border-sky-400 shadow-lg shadow-sky-950/60 ring-2 ring-sky-400/30',
      glow: 'from-sky-600/20 via-transparent to-transparent',
      dotColor: '#00A8E1',
      isLocal: false,
    };
  }

  if (p === 'apple_tv' || p === 'apple') {
    return {
      key: 'apple_tv',
      name: 'Apple TV+',
      shortName: 'Apple TV+',
      color: '#A1A1AA',
      badgeBg: 'bg-zinc-800',
      badgeBorder: 'border-zinc-500/40',
      badgeShadow: 'shadow-zinc-950/60',
      pillBg: 'bg-zinc-800/40 text-zinc-300 border-zinc-600/40 hover:bg-zinc-800/60',
      activePill: 'bg-zinc-700 text-white border-zinc-500 shadow-lg shadow-zinc-950/60 ring-2 ring-zinc-400/30',
      glow: 'from-zinc-600/20 via-transparent to-transparent',
      dotColor: '#A1A1AA',
      isLocal: false,
    };
  }

  if (p === 'paramount' || p === 'paramount_plus') {
    return {
      key: 'paramount',
      name: 'Paramount+',
      shortName: 'Paramount+',
      color: '#0064FF',
      badgeBg: 'bg-[#0064FF]',
      badgeBorder: 'border-blue-400/40',
      badgeShadow: 'shadow-blue-950/60',
      pillBg: 'bg-blue-700/20 text-blue-300 border-blue-600/40 hover:bg-blue-700/30',
      activePill: 'bg-[#0064FF] text-white border-blue-400 shadow-lg shadow-blue-950/60 ring-2 ring-blue-400/30',
      glow: 'from-blue-700/20 via-transparent to-transparent',
      dotColor: '#0064FF',
      isLocal: false,
    };
  }

  // Por defecto: Local On-Net FlixHN
  return {
    key: 'local',
    name: 'FlixHN',
    shortName: 'FlixHN',
    color: '#E50914',
    badgeBg: 'bg-black/85',
    badgeBorder: 'border-red-500/35',
    badgeShadow: 'shadow-black/70',
    pillBg: 'bg-zinc-800/60 text-zinc-300 border-zinc-700 hover:bg-zinc-800',
    activePill: 'bg-[#E50914] text-white border-red-500 shadow-lg shadow-red-950/60 ring-2 ring-red-400/30',
    glow: 'from-red-600/10 via-transparent to-transparent',
    dotColor: '#E50914',
    isLocal: true,
  };
};

const MovieCard = ({ item, title: propTitle, isProgress, onPlay, onOpenModal, onRemoveProgress, onError, ...restProps }) => {
  // 1. Declaración incondicional de hooks al inicio
  const cardRef = useRef(null);
  let hoverTimeout = useRef(null);
  let closeTimeout = useRef(null);
  const [isHovered, setIsHovered] = useState(false);
  const [cardRect, setCardRect] = useState(null);

  // Manejar si el item viene de prop directa title, PlaybackProgress o Title directo
  const title = propTitle || (isProgress ? item?.title : item);
  const episode = isProgress ? item?.episode : null;

  // Temporizador con retardo (debounce) para apertura y cierre fluido estilo Netflix
  const handleMouseEnter = useCallback(() => {
    if (closeTimeout.current) clearTimeout(closeTimeout.current);
    hoverTimeout.current = setTimeout(() => {
      if (!cardRef.current) return;
      setCardRect(cardRef.current.getBoundingClientRect());
      setIsHovered(true);
    }, 300); // 300ms de espera antes de abrir
  }, []);

  const handleMouseLeave = useCallback(() => {
    if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
    closeTimeout.current = setTimeout(() => {
      setIsHovered(false);
    }, 200); // margen para que no parpadee si el cursor pasa a la tarjeta flotante
  }, []);

  // Handlers para el contenedor flotante HoverPreviewCard para mantenerla fija y sólida
  const handlePreviewMouseEnter = useCallback(() => {
    if (closeTimeout.current) {
      clearTimeout(closeTimeout.current);
      closeTimeout.current = null;
    }
    setIsHovered(true);
  }, []);

  const handlePreviewMouseLeave = useCallback(() => {
    if (hoverTimeout.current) {
      clearTimeout(hoverTimeout.current);
      hoverTimeout.current = null;
    }
    closeTimeout.current = setTimeout(() => {
      setIsHovered(false);
    }, 200); // margen de 200ms al salir de la tarjeta flotante
  }, []);

  const handleClosePreview = useCallback(() => {
    if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
    if (closeTimeout.current) clearTimeout(closeTimeout.current);
    setIsHovered(false);
  }, []);

  // Limpieza al desmontar
  useEffect(() => {
    return () => {
      if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
      if (closeTimeout.current) clearTimeout(closeTimeout.current);
    };
  }, []);

  if (!title) return null;

  const rawPoster = isProgress && episode?.thumbnail_url
    ? episode.thumbnail_url
    : (title?.poster_url || title?.image || title?.poster);
  const posterUrl = resolvePosterUrl(rawPoster);

  // Detección de proveedor/plataforma OTT ('netflix', 'disney', 'hbo_max', 'prime', 'local')
  const providerRaw = title?.provider || title?.source_platform || item?.provider || item?.source_platform || 'local';
  const platform = getPlatformConfig(providerRaw);

  // Cálculo de porcentaje de progreso visto
  const percentWatched = isProgress && item.duration_seconds > 0
    ? Math.min(100, Math.round((item.current_seconds / item.duration_seconds) * 100))
    : 0;

  // Lógica de nuevo lanzamiento (≤ 14 días desde ingreso o prop is_new)
  const isNew = !isProgress && (
    item?.is_new ?? (
      title?.created_at
        ? (new Date().getTime() - new Date(title.created_at).getTime()) / (1000 * 60 * 60 * 24) <= 14
        : false
    )
  );

  return (
    <>
      <div
        ref={cardRef}
        onClick={() => onOpenModal && onOpenModal(title)}
        onMouseEnter={handleMouseEnter}
        onMouseLeave={handleMouseLeave}
        className={`group relative flex-shrink-0 w-36 sm:w-44 md:w-56 bg-zinc-100 dark:bg-[#181818] rounded-md overflow-hidden cursor-pointer shadow-sm dark:shadow-lg border border-zinc-300/40 dark:border-zinc-800 transition-all duration-300 ease-out select-none ${
          isHovered
            ? 'z-20 ring-2 ring-[#E50914]/50 shadow-2xl'
            : 'hover:scale-105 hover:z-20 hover:shadow-2xl hover:ring-2 hover:ring-[#E50914]/50'
        }`}
      >
        {/* Imagen del Poster */}
        <div className="relative aspect-[16/9] md:aspect-[2/3] w-full overflow-hidden bg-zinc-200 dark:bg-neutral-900">
          <img
            src={posterUrl}
            alt={title.name || title.title}
            loading="lazy"
            onError={(e) => {
              if (e && e.target) {
                e.target.onerror = null;
                e.target.src = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500';
              }
              if (typeof onError === 'function') {
                onError(e);
              }
            }}
            className="w-full h-full object-cover object-center group-hover:brightness-110 transition-all duration-300 rounded"
          />

          {/* Badges en la esquina superior izquierda: Plataforma Distintiva + Tipo */}
          <div className="absolute top-2 left-2 z-10 pointer-events-none flex flex-col items-start gap-1">
            {/* Badge de Plataforma */}
            <span
              className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded shadow-lg backdrop-blur-md border text-[9px] sm:text-[10px] font-black uppercase tracking-wider ${platform.badgeBg} ${platform.badgeBorder} ${platform.badgeShadow} text-white`}
            >
              {platform.isLocal ? (
                <>
                  <span className="w-1.5 h-1.5 rounded-full bg-[#E50914] shadow-sm animate-pulse" />
                  <span>FlixHN</span>
                </>
              ) : (
                <span>{platform.shortName}</span>
              )}
            </span>

            {/* Badge sutil de tipo (Película / Serie) */}
            <span className="bg-black/75 backdrop-blur-sm text-[8px] sm:text-[9px] text-zinc-300 font-bold px-1.5 py-0.5 rounded uppercase border border-white/10 shadow">
              {title.type === 'movie' ? 'Película' : 'Serie'}
            </span>
          </div>

          {/* Insignia Dinámica Roja Brillante Estilo Netflix: NUEVO / RECIÉN AGREGADO */}
          {isNew && (
            <div className="absolute top-2 right-2 z-10 pointer-events-none">
              <span className="bg-gradient-to-r from-red-600 to-[#E50914] text-white text-[9px] sm:text-[10px] font-black px-1.5 py-0.5 rounded shadow-lg uppercase tracking-wider border border-red-400/50 ring-1 ring-red-500/50 animate-pulse">
                NUEVO
              </span>
            </div>
          )}

          {/* Botón flotante para quitar de 'Continuar Viendo' */}
          {isProgress && onRemoveProgress && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onRemoveProgress(title.id, episode?.id);
              }}
              className="absolute top-2 right-2 z-20 w-7 h-7 rounded-full bg-black/70 hover:bg-[#E50914] text-white/80 hover:text-white flex items-center justify-center backdrop-blur-sm border border-white/20 transition-all opacity-0 group-hover:opacity-100 shadow-lg cursor-pointer hover:scale-110 active:scale-95"
              title="Quitar de Continuar Viendo"
              type="button"
            >
              <X className="w-3.5 h-3.5 stroke-[2.5]" />
            </button>
          )}

          {/* Botón flotante de reproducción en hover */}
          <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
            <button
              onClick={(e) => {
                e.stopPropagation();
                if (onPlay) onPlay(title, episode);
              }}
              className="w-12 h-12 rounded-full bg-[#E50914] text-white flex items-center justify-center hover:bg-[#F40612] hover:scale-110 active:scale-95 transition-all shadow-xl pointer-events-auto cursor-pointer"
              title="Reproducir ahora"
              type="button"
            >
              <Play className="w-6 h-6 fill-white ml-0.5" />
            </button>
          </div>

          {/* Barra Roja de Progreso de Netflix para 'Continuar Viendo' */}
          {isProgress && percentWatched > 0 && (
            <div className="absolute bottom-0 left-0 right-0 h-1.5 bg-neutral-800/80 z-10">
              <div
                className="h-full bg-[#E50914]"
                style={{ width: `${percentWatched}%` }}
              />
            </div>
          )}
        </div>

        {/* Información condensada en el pie de la tarjeta */}
        <div className="p-2.5 space-y-1 bg-zinc-100 dark:bg-[#181818] border-t border-zinc-200/50 dark:border-zinc-800/80 transition-colors">
          <h3 className="font-semibold text-xs md:text-sm text-zinc-900 dark:text-zinc-100 truncate group-hover:text-[#E50914] transition-colors">
            {title.name}
          </h3>
          
          {isProgress && episode && (
            <p className="text-[11px] text-zinc-500 dark:text-zinc-400 truncate">
              {episode.title}
            </p>
          )}

          <div className="flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
            <span>{title.release_year > 1900 ? title.release_year : ''}</span>
            <span className="truncate max-w-[95px]">{title.genre}</span>
          </div>
        </div>
      </div>

      {/* Tarjeta Flotante Expandida con Resumen en Hover */}
      {isHovered && cardRect && (
        <HoverPreviewCard
          item={title}
          episode={episode}
          rect={cardRect}
          onClose={handleClosePreview}
          onMouseEnter={handlePreviewMouseEnter}
          onMouseLeave={handlePreviewMouseLeave}
          onPlay={onPlay}
          onOpenModal={onOpenModal}
          isProgress={isProgress}
        />
      )}
    </>
  );
};

export default MovieCard;
