import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { Play } from 'lucide-react';
import Top10Number from './Top10Number';
import HoverPreviewCard from './HoverPreviewCard';
import { getPlatformConfig } from './MovieCard';

/**
 * Tarjeta individual para la fila de Top 10 con Número Gigante y vista previa en hover
 */
const Top10Card = ({ item, rank, onPlay, onOpenModal }) => {
  // 1. Declaración incondicional de hooks al inicio
  const cardRef = useRef(null);
  const hoverTimerRef = useRef(null);
  const [isHovered, setIsHovered] = useState(false);
  const [cardRect, setCardRect] = useState(null);

  const title = item?.title || item;

  // Manejo de entrada del cursor con retardo sutil de 380ms para evitar disparos accidentales
  const handleMouseEnter = useCallback(() => {
    if (hoverTimerRef.current) clearTimeout(hoverTimerRef.current);

    hoverTimerRef.current = setTimeout(() => {
      if (cardRef.current) {
        setCardRect(cardRef.current.getBoundingClientRect());
        setIsHovered(true);
      }
    }, 380);
  }, []);

  // Manejo de salida del cursor
  const handleMouseLeave = useCallback(() => {
    if (hoverTimerRef.current) {
      clearTimeout(hoverTimerRef.current);
      hoverTimerRef.current = null;
    }
    // Pequeño retardo de gracia para transiciones entre el póster y la tarjeta desplegada
    setTimeout(() => {
      setIsHovered(false);
    }, 150);
  }, []);

  const handleClosePreview = useCallback(() => {
    setIsHovered(false);
  }, []);

  // Limpieza al desmontar
  useEffect(() => {
    return () => {
      if (hoverTimerRef.current) clearTimeout(hoverTimerRef.current);
    };
  }, []);

  if (!title) return null;

  const posterUrl = title.poster_url || title.image || title.poster || '/img/placeholder-poster.png';
  const providerRaw = title.provider || title.source_platform || item?.provider || item?.source_platform || 'local';
  const platform = getPlatformConfig(providerRaw);

  return (
    <>
      <div
        ref={cardRef}
        onClick={() => onOpenModal && onOpenModal(title)}
        onMouseEnter={handleMouseEnter}
        onMouseLeave={handleMouseLeave}
        className="group relative flex items-end flex-shrink-0 cursor-pointer select-none my-2 transition-transform duration-300"
      >
        {/* Número Gigante a la izquierda */}
        <Top10Number rank={rank} />

        {/* Contenedor del Póster Superpuesto sobre el número */}
        <div className="-ml-5 sm:-ml-7 md:-ml-9 relative z-10 w-28 sm:w-36 md:w-44 aspect-[2/3] rounded-md overflow-hidden bg-zinc-900 border border-zinc-800 shadow-xl transition-all duration-300 ease-out group-hover:scale-105 group-hover:z-20 group-hover:shadow-2xl group-hover:ring-2 group-hover:ring-[#E50914]/60">
          <img
            src={posterUrl}
            alt={title.name || title.title}
            loading="lazy"
            onError={(e) => {
              e.target.onerror = null;
              e.target.src = '/img/placeholder-poster.png';
            }}
            className="w-full h-full object-cover object-center group-hover:brightness-110 transition-all duration-300"
          />

          {/* Badges de Plataforma Distintiva y Tipo en esquina superior izquierda */}
          <div className="absolute top-2 left-2 z-10 pointer-events-none flex flex-col items-start gap-1">
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
          </div>

          {/* Badge Distintivo TOP 10 en esquina superior derecha */}
          <div className="absolute top-2 right-2 z-10 pointer-events-none">
            <span className="bg-[#E50914] text-white text-[9px] sm:text-[10px] font-black px-1.5 py-0.5 rounded shadow-lg uppercase tracking-wider border border-white/20">
              TOP 10
            </span>
          </div>

          {/* Botón flotante de reproducción en hover */}
          <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                if (onPlay) onPlay(title);
              }}
              className="w-11 h-11 rounded-full bg-[#E50914] text-white flex items-center justify-center hover:bg-[#F40612] hover:scale-110 active:scale-95 transition-all shadow-xl pointer-events-auto cursor-pointer"
              title="Reproducir ahora"
            >
              <Play className="w-5 h-5 fill-white ml-0.5" />
            </button>
          </div>
        </div>
      </div>

      {/* Tarjeta Flotante Expandida con Resumen en Hover */}
      {isHovered && cardRect && (
        <HoverPreviewCard
          item={title}
          rect={cardRect}
          onClose={handleClosePreview}
          onPlay={onPlay}
          onOpenModal={onOpenModal}
        />
      )}
    </>
  );
};

export default Top10Card;
