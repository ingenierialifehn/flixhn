import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { ChevronLeft, ChevronRight, TrendingUp } from 'lucide-react';
import Top10Card from './Top10Card';

/**
 * Fila destacada "Top 10 en FlixHN hoy" con números gigantes estilo Netflix.
 */
const Top10Row = ({ items, currentTab = 'home', onPlay, onOpenModal }) => {
  const rowRef = useRef(null);
  const [showLeftArrow, setShowLeftArrow] = useState(false);
  const [showRightArrow, setShowRightArrow] = useState(true);

  // Título dinámico adaptado a la pestaña activa
  const rowTitle = useMemo(() => {
    if (currentTab === 'movies') return 'Las 10 Películas más populares hoy';
    if (currentTab === 'series') return 'Las 10 Series más populares hoy';
    return 'Las 10 Series y Películas más populares hoy';
  }, [currentTab]);

  const handleScroll = (direction) => {
    if (rowRef.current) {
      const { scrollLeft, clientWidth } = rowRef.current;
      const scrollTo = direction === 'left' ? scrollLeft - clientWidth * 0.75 : scrollLeft + clientWidth * 0.75;
      rowRef.current.scrollTo({ left: scrollTo, behavior: 'smooth' });
      setTimeout(() => {
        if (rowRef.current) {
          setShowLeftArrow(rowRef.current.scrollLeft > 20);
          setShowRightArrow(
            rowRef.current.scrollLeft + rowRef.current.clientWidth < rowRef.current.scrollWidth - 20
          );
        }
      }, 350);
    }
  };

  const onScrollCheck = () => {
    if (rowRef.current) {
      setShowLeftArrow(rowRef.current.scrollLeft > 20);
      setShowRightArrow(
        rowRef.current.scrollLeft + rowRef.current.clientWidth < rowRef.current.scrollWidth - 20
      );
    }
  };

  if (!items || items.length === 0) return null;

  const top10List = items.slice(0, 10);

  return (
    <div className="space-y-2 md:space-y-3 px-4 md:px-12 my-6 md:my-8 group relative select-none">
      {/* Encabezado de la Fila con Título y Badge */}
      <div className="flex items-center gap-3">
        <h2 className="text-lg md:text-2xl font-bold text-gray-900 dark:text-white tracking-wide hover:text-[#E50914] cursor-pointer transition-colors inline-flex items-center gap-2">
          <TrendingUp className="w-5 h-5 text-[#E50914] stroke-[2.5]" />
          <span>{rowTitle}</span>
        </h2>
        <span className="text-[10px] md:text-xs font-black text-[#E50914] uppercase tracking-widest bg-red-600/10 border border-red-500/25 px-2 py-0.5 rounded shadow-sm">
          TOP 10 HOY
        </span>
      </div>

      {/* Contenedor del Carrusel con Controles Laterales */}
      <div className="relative">
        {/* Flecha Izquierda con desvanecimiento sutil */}
        {showLeftArrow && (
          <button
            type="button"
            onClick={() => handleScroll('left')}
            className="absolute left-0 top-0 bottom-0 z-40 w-12 md:w-16 bg-gradient-to-r from-black/85 via-black/40 to-transparent hover:from-black text-white flex items-center justify-start pl-1 md:pl-2 opacity-0 group-hover:opacity-100 transition-all duration-300 cursor-pointer"
            aria-label="Desplazar a la izquierda"
          >
            <ChevronLeft className="w-8 h-8 md:w-10 md:h-10 stroke-[2.5] text-white/90 hover:text-white drop-shadow-md transition-colors" />
          </button>
        )}

        {/* Fila Desplazable de Tarjetas con Números Gigantes */}
        <div
          ref={rowRef}
          onScroll={onScrollCheck}
          className="flex items-center gap-4 sm:gap-6 md:gap-8 overflow-x-auto no-scrollbar scroll-smooth py-4 px-2"
        >
          {top10List.map((item, idx) => (
            <Top10Card
              key={item?.id || idx}
              item={item}
              rank={idx + 1}
              onPlay={onPlay}
              onOpenModal={onOpenModal}
            />
          ))}
        </div>

        {/* Flecha Derecha con desvanecimiento sutil */}
        {showRightArrow && (
          <button
            type="button"
            onClick={() => handleScroll('right')}
            className="absolute right-0 top-0 bottom-0 z-40 w-12 md:w-16 bg-gradient-to-l from-black/85 via-black/40 to-transparent hover:from-black text-white flex items-center justify-end pr-1 md:pr-2 opacity-0 group-hover:opacity-100 transition-all duration-300 cursor-pointer"
            aria-label="Desplazar a la derecha"
          >
            <ChevronRight className="w-8 h-8 md:w-10 md:h-10 stroke-[2.5] text-white/90 hover:text-white drop-shadow-md transition-colors" />
          </button>
        )}
      </div>
    </div>
  );
};

export default Top10Row;
