import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { Play, Plus, Check, ThumbsUp, ChevronDown, Volume2, VolumeX, Sparkles } from 'lucide-react';
import { getPlatformConfig } from './MovieCard';
import { resolvePosterUrl, handlePosterError } from '../utils/media';

/**
 * Tarjeta Flotante de Vista Previa en Hover estilo Netflix (Hover Card Preview).
 * Se monta mediante Portal sobre document.body para garantizar elevación z-50
 * sin recortes por overflow en los contenedores de los carruseles.
 */
const HoverPreviewCard = ({
  item,
  episode = null,
  rect,
  onClose,
  onMouseEnter,
  onMouseLeave,
  onPlay,
  onOpenModal,
  isProgress = false,
}) => {
  // 1. Declaración incondicional de todos los Hooks al inicio
  const containerRef = useRef(null);
  let hoverTimeout = useRef(null);
  let closeTimeout = useRef(null);
  const [isMyList, setIsMyList] = useState(false);
  const [isLiked, setIsLiked] = useState(false);
  const [isMuted, setIsMuted] = useState(true);
  const [isMounted, setIsMounted] = useState(false);

  // Handlers para mantener fija y sólida la tarjeta flotante mientras el ratón esté sobre ella
  const handleContainerMouseEnter = useCallback(
    (e) => {
      if (closeTimeout.current) {
        clearTimeout(closeTimeout.current);
        closeTimeout.current = null;
      }
      if (typeof onMouseEnter === 'function') {
        onMouseEnter(e);
      }
    },
    [onMouseEnter]
  );

  const handleContainerMouseLeave = useCallback(
    (e) => {
      if (hoverTimeout.current) {
        clearTimeout(hoverTimeout.current);
        hoverTimeout.current = null;
      }
      if (typeof onMouseLeave === 'function') {
        onMouseLeave(e);
      } else if (onClose) {
        closeTimeout.current = setTimeout(() => {
          onClose();
        }, 200);
      }
    },
    [onMouseLeave, onClose]
  );

  useEffect(() => {
    return () => {
      if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
      if (closeTimeout.current) clearTimeout(closeTimeout.current);
    };
  }, []);

  // Normalización del objeto title
  const title = useMemo(() => {
    return item?.title || item;
  }, [item]);

  // Cargar estado reactivo de "Mi Lista" y "Me gusta" desde localStorage
  useEffect(() => {
    if (!title?.id) return;
    try {
      const listData = JSON.parse(localStorage.getItem('flixhn_my_list') || '[]');
      setIsMyList(listData.some((tId) => String(tId) === String(title.id)));

      const likesData = JSON.parse(localStorage.getItem('flixhn_likes') || '[]');
      setIsLiked(likesData.some((tId) => String(tId) === String(title.id)));
    } catch (e) {
      // Manejo seguro sin alert
    }
    setIsMounted(true);
  }, [title?.id]);

  // Cerrar suavemente al hacer scroll pronunciado o redimensionar pantalla
  useEffect(() => {
    const handleScrollOrResize = () => {
      if (onClose) onClose();
    };

    window.addEventListener('scroll', handleScrollOrResize, { passive: true });
    window.addEventListener('resize', handleScrollOrResize, { passive: true });

    return () => {
      window.removeEventListener('scroll', handleScrollOrResize);
      window.removeEventListener('resize', handleScrollOrResize);
    };
  }, [onClose]);

  // Alternar "Mi Lista"
  const handleToggleMyList = useCallback(
    (e) => {
      e?.stopPropagation();
      if (!title?.id) return;

      try {
        const listData = JSON.parse(localStorage.getItem('flixhn_my_list') || '[]');
        const exists = listData.some((tId) => String(tId) === String(title.id));
        let updated;
        if (exists) {
          updated = listData.filter((tId) => String(tId) !== String(title.id));
          setIsMyList(false);
        } else {
          updated = [...listData, title.id];
          setIsMyList(true);
        }
        localStorage.setItem('flixhn_my_list', JSON.stringify(updated));
      } catch (err) {
        // silencioso
      }
    },
    [title?.id]
  );

  // Alternar "Me gusta"
  const handleToggleLike = useCallback(
    (e) => {
      e?.stopPropagation();
      if (!title?.id) return;

      try {
        const likesData = JSON.parse(localStorage.getItem('flixhn_likes') || '[]');
        const exists = likesData.some((tId) => String(tId) === String(title.id));
        let updated;
        if (exists) {
          updated = likesData.filter((tId) => String(tId) !== String(title.id));
          setIsLiked(false);
        } else {
          updated = [...likesData, title.id];
          setIsLiked(true);
        }
        localStorage.setItem('flixhn_likes', JSON.stringify(updated));
      } catch (err) {
        // silencioso
      }
    },
    [title?.id]
  );

  // Acción de Reproducir
  const handlePlayClick = useCallback(
    (e) => {
      e?.stopPropagation();
      if (onClose) onClose();
      if (onPlay) onPlay(title, episode);
    },
    [onClose, onPlay, title, episode]
  );

  // Acción de Abrir Modal Completo
  const handleMoreInfoClick = useCallback(
    (e) => {
      e?.stopPropagation();
      if (onClose) onClose();
      if (onOpenModal) onOpenModal(title);
    },
    [onClose, onOpenModal, title]
  );

  // Cálculo de duración o temporadas
  const durationOrSeasons = useMemo(() => {
    if (title?.type === 'series') {
      const seasonsCount = title.seasons_count || title.seasons?.length || 1;
      return `${seasonsCount} ${seasonsCount === 1 ? 'temporada' : 'temporadas'}`;
    }

    const sec = parseInt(title?.duration_seconds, 10);
    if (sec && sec > 0) {
      const hours = Math.floor(sec / 3600);
      const minutes = Math.floor((sec % 3600) / 60);
      if (hours > 0) {
        return `${hours} h ${minutes > 0 ? `${minutes} min` : ''}`;
      }
      return `${minutes} min`;
    }

    // Estimación estética coherente si no viene duración
    return '1 h 48 min';
  }, [title]);

  // Calificación por edad estimada o real
  const ageRating = useMemo(() => {
    if (title?.rating || title?.age_rating) {
      return String(title.rating || title.age_rating).toUpperCase();
    }
    const g = String(title?.genre || '').toLowerCase();
    if (g.includes('terror') || g.includes('crimen') || g.includes('horror')) return '+18';
    if (g.includes('infantil') || g.includes('animac') || g.includes('familia')) return 'ATP';
    if (g.includes('acción') || g.includes('suspenso') || g.includes('thriller')) return '+16';
    return '+13';
  }, [title]);

  // Porcentaje de coincidencia estilo Netflix (94% - 99%)
  const matchPercentage = useMemo(() => {
    const seed = (title?.id || 1) * 7 + (title?.name?.length || 5);
    return 94 + (seed % 6);
  }, [title]);

  // Lista de géneros limpios para píldoras
  const genreList = useMemo(() => {
    const raw = title?.genre || '';
    return raw
      .split(/[\/,•-]/)
      .map((g) => g.trim())
      .filter((g) => g.length > 1)
      .slice(0, 3);
  }, [title]);

  // Plataforma OTT y branding
  const providerRaw =
    title?.provider || title?.source_platform || item?.provider || item?.source_platform || 'local';
  const platform = useMemo(() => getPlatformConfig(providerRaw), [providerRaw]);

  // Imagen del Banner panorámico (Backdrop preferido, poster como fallback)
  const rawBanner = isProgress && episode?.thumbnail_url
    ? episode.thumbnail_url
    : (title?.backdrop_url || title?.poster_url || title?.image);
  const bannerUrl = resolvePosterUrl(rawBanner);

  // Si no hay coordenadas o título, no renderizar
  if (!rect || !title) return null;

  // 2. Cálculo de Posicionamiento Geométrico y Manejo de Bordes de Pantalla
  const viewportWidth = typeof window !== 'undefined' ? window.innerWidth : 1200;
  const scrollY = typeof window !== 'undefined' ? window.scrollY : 0;

  // Ancho expandido de la tarjeta flotante (escalado fluido estilo Netflix)
  const cardWidth = Math.max(310, Math.min(360, Math.round(rect.width * 1.35)));

  // Centrado horizontal sobre la tarjeta origen
  let left = rect.left + (rect.width - cardWidth) / 2;
  let originClass = 'origin-center';

  // Detección de Borde Izquierdo
  if (left < 16) {
    left = 16;
    originClass = 'origin-left';
  }
  // Detección de Borde Derecho
  else if (left + cardWidth > viewportWidth - 16) {
    left = viewportWidth - cardWidth - 16;
    originClass = 'origin-right';
  }

  // Posicionamiento Vertical con elevación suave
  let top = rect.top + scrollY - 35;
  if (top - scrollY < 65) {
    top = scrollY + 65; // Evitar colisión con Navbar
  }

  const content = (
    <div
      ref={containerRef}
      onMouseEnter={handleContainerMouseEnter}
      onMouseLeave={handleContainerMouseLeave}
      style={{
        position: 'absolute',
        top: `${top}px`,
        left: `${left}px`,
        width: `${cardWidth}px`,
      }}
      className={`z-50 select-none animate-fadeIn ${originClass} transition-all duration-300 ease-out pointer-events-auto`}
    >
      <div className="bg-[#141414] dark:bg-[#141414] rounded-xl overflow-hidden shadow-[0_20px_50px_rgba(0,0,0,0.95)] border border-zinc-700/60 ring-1 ring-white/10 flex flex-col">
        {/* Banner Superior / Backdrop Panorámico con degradado inferior */}
        <div className="relative aspect-[16/9] w-full overflow-hidden bg-zinc-900">
          <img
            src={bannerUrl}
            alt={title.name || title.title}
            onError={handlePosterError}
            className="w-full h-full object-cover object-center filter brightness-95 hover:brightness-105 transition-all duration-300"
          />

          {/* Degradado inferior cinematográfico para fusionar con el panel de información */}
          <div className="absolute inset-0 bg-gradient-to-t from-[#141414] via-[#141414]/30 to-transparent pointer-events-none" />

          {/* Badges de Plataforma Distintiva en esquina superior izquierda */}
          <div className="absolute top-2.5 left-2.5 z-10 flex items-center gap-1.5">
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded shadow-lg backdrop-blur-md border text-[9px] sm:text-[10px] font-black uppercase tracking-wider ${platform.badgeBg} ${platform.badgeBorder} ${platform.badgeShadow} text-white`}
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

            <span className="bg-black/80 backdrop-blur-sm text-[8px] sm:text-[9px] text-zinc-300 font-bold px-1.5 py-0.5 rounded uppercase border border-white/15 shadow">
              {title.type === 'movie' ? 'Película' : 'Serie'}
            </span>
          </div>

          {/* Botón flotante de audio/mute en esquina superior derecha */}
          <div className="absolute top-2.5 right-2.5 z-10">
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setIsMuted(!isMuted);
              }}
              className="w-7 h-7 rounded-full bg-black/60 hover:bg-black/90 text-white flex items-center justify-center border border-white/20 transition-all cursor-pointer shadow-md"
              title={isMuted ? 'Activar sonido' : 'Silenciar'}
            >
              {isMuted ? <VolumeX className="w-3.5 h-3.5 text-zinc-300" /> : <Volume2 className="w-3.5 h-3.5 text-white" />}
            </button>
          </div>

          {/* Título en la parte inferior del Banner */}
          <div className="absolute bottom-2 left-3 right-3 pointer-events-none">
            <h3 className="font-display text-xl sm:text-2xl font-black text-white tracking-wide leading-tight drop-shadow-[0_2px_8px_rgba(0,0,0,0.95)] truncate">
              {title.name}
            </h3>
          </div>
        </div>

        {/* Panel de Contenido Desplegado */}
        <div className="p-3.5 sm:p-4 space-y-3 bg-[#141414] text-white">
          {/* Fila de Botones de Acción Rápida estilo Netflix */}
          <div className="flex items-center gap-2">
            {/* 1. Botón Circular Blanco: Reproducir */}
            <button
              type="button"
              onClick={handlePlayClick}
              className="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white hover:bg-white/85 text-black flex items-center justify-center shadow-lg transition-transform hover:scale-110 active:scale-95 cursor-pointer flex-shrink-0"
              title="Reproducir ahora"
            >
              <Play className="w-5 h-5 fill-black ml-0.5" />
            </button>

            {/* 2. Botón Circular: Agregar / Quitar de Mi Lista */}
            <button
              type="button"
              onClick={handleToggleMyList}
              className={`w-9 h-9 sm:w-10 sm:h-10 rounded-full border flex items-center justify-center transition-all hover:scale-110 active:scale-95 cursor-pointer flex-shrink-0 ${
                isMyList
                  ? 'bg-emerald-600/30 border-emerald-500 text-emerald-400'
                  : 'bg-zinc-800/80 hover:bg-zinc-700 text-white border-white/30 hover:border-white'
              }`}
              title={isMyList ? 'Quitar de Mi Lista' : 'Agregar a Mi Lista'}
            >
              {isMyList ? (
                <Check className="w-4 h-4 text-emerald-400 stroke-[2.8]" />
              ) : (
                <Plus className="w-4 h-4 stroke-[2.5]" />
              )}
            </button>

            {/* 3. Botón Circular: Me gusta (ThumbsUp) */}
            <button
              type="button"
              onClick={handleToggleLike}
              className={`w-9 h-9 sm:w-10 sm:h-10 rounded-full border flex items-center justify-center transition-all hover:scale-110 active:scale-95 cursor-pointer flex-shrink-0 ${
                isLiked
                  ? 'bg-red-600/30 border-red-500 text-[#E50914]'
                  : 'bg-zinc-800/80 hover:bg-zinc-700 text-white border-white/30 hover:border-white'
              }`}
              title={isLiked ? 'Te gusta este título' : 'Me gusta'}
            >
              <ThumbsUp className={`w-4 h-4 ${isLiked ? 'fill-current' : ''}`} />
            </button>

            {/* 4. Botón Circular Derecho: Más Información (ChevronDown) */}
            <button
              type="button"
              onClick={handleMoreInfoClick}
              className="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-zinc-800/80 hover:bg-zinc-700 text-white border border-white/30 hover:border-white flex items-center justify-center ml-auto transition-all hover:scale-110 active:scale-95 cursor-pointer flex-shrink-0"
              title="Más información y episodios"
            >
              <ChevronDown className="w-5 h-5 stroke-[2.5]" />
            </button>
          </div>

          {/* Fila de Metadatos Esenciales */}
          <div className="flex items-center flex-wrap gap-2 text-xs">
            {/* Porcentaje de coincidencia estilo Netflix */}
            <span className="text-emerald-400 font-black tracking-wide flex items-center gap-1">
              <Sparkles className="w-3 h-3 fill-emerald-400/40" />
              {matchPercentage}% para ti
            </span>

            {/* Calificación por edad */}
            <span className="text-[10px] text-zinc-300 font-bold border border-zinc-600/80 px-1.5 py-0.5 rounded uppercase bg-black/40">
              {ageRating}
            </span>

            {/* Duración o Temporadas */}
            <span className="text-zinc-300 font-semibold">{durationOrSeasons}</span>

            {/* Insignia de Resolución */}
            <span className="text-[9px] text-zinc-300 font-black border border-zinc-600/80 px-1 py-0.5 rounded tracking-wider bg-black/40">
              4K ULTRA HD
            </span>

            {/* Audio espacial */}
            <span className="text-[9px] text-zinc-400 font-bold border border-zinc-700/80 px-1 py-0.5 rounded bg-black/30">
              5.1
            </span>
          </div>

          {/* Sinopsis / Resumen Breve */}
          {title.description && (
            <p className="text-xs text-zinc-300/90 line-clamp-3 leading-relaxed font-normal">
              {title.description}
            </p>
          )}

          {/* Píldoras de Géneros Principales */}
          {genreList.length > 0 && (
            <div className="flex items-center flex-wrap gap-1.5 text-[11px] text-zinc-400 pt-1 border-t border-zinc-800/80">
              {genreList.map((genre, idx) => (
                <React.Fragment key={genre}>
                  <span className="text-zinc-300 hover:text-white transition-colors">
                    {genre}
                  </span>
                  {idx < genreList.length - 1 && (
                    <span className="text-zinc-600 select-none">•</span>
                  )}
                </React.Fragment>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );

  return typeof document !== 'undefined' ? createPortal(content, document.body) : null;
};

export default HoverPreviewCard;
