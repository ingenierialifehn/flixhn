import React, { useRef, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import MovieCard from './MovieCard';

const MovieRow = ({ title, items, isProgressRow = false, onPlay, onOpenModal, onRemoveProgress }) => {
  const rowRef = useRef(null);
  const [showLeftArrow, setShowLeftArrow] = useState(false);
  const [showRightArrow, setShowRightArrow] = useState(true);

  if (!items || items.length === 0) return null;

  const handleScroll = (direction) => {
    if (rowRef.current) {
      const { scrollLeft, clientWidth, scrollWidth } = rowRef.current;
      const scrollAmount = clientWidth * 0.75;
      const newScrollLeft = direction === 'left' ? scrollLeft - scrollAmount : scrollLeft + scrollAmount;

      rowRef.current.scrollTo({
        left: newScrollLeft,
        behavior: 'smooth',
      });

      // Actualizar visibilidad de flechas tras animación
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

  return (
    <div className="space-y-2 md:space-y-3 px-4 md:px-12 my-6 md:my-8 group relative select-none">
      {/* Título de la Fila */}
      <h2 className="text-lg md:text-2xl font-bold text-gray-900 dark:text-white tracking-wide hover:text-[#E50914] cursor-pointer transition-colors inline-block">
        {title}
      </h2>

      {/* Contenedor del Carrusel con Controles de Navegación Lateral */}
      <div className="relative">
        {/* Flecha Izquierda */}
        {showLeftArrow && (
          <button
            onClick={() => handleScroll('left')}
            className="absolute left-0 top-0 bottom-0 z-40 w-10 md:w-14 bg-transparent hover:bg-black/20 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all cursor-pointer"
            aria-label="Desplazar a la izquierda"
          >
            <ChevronLeft className="w-8 h-8 stroke-[2.5] text-white/90 hover:text-white drop-shadow-md transition-colors" />
          </button>
        )}

        {/* Fila Desplazable de Tarjetas */}
        <div
          ref={rowRef}
          onScroll={onScrollCheck}
          className="flex items-center gap-2.5 md:gap-4 overflow-x-auto no-scrollbar scroll-smooth py-4 px-1"
        >
          {(items || []).map((item, idx) => (
            <MovieCard
              key={item?.id || idx}
              item={item}
              isProgress={isProgressRow}
              onPlay={onPlay}
              onOpenModal={onOpenModal}
              onRemoveProgress={onRemoveProgress}
            />
          ))}
        </div>

        {/* Flecha Derecha */}
        {showRightArrow && (
          <button
            onClick={() => handleScroll('right')}
            className="absolute right-0 top-0 bottom-0 z-40 w-10 md:w-14 bg-transparent hover:bg-black/20 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all cursor-pointer"
            aria-label="Desplazar a la derecha"
          >
            <ChevronRight className="w-8 h-8 stroke-[2.5] text-white/90 hover:text-white drop-shadow-md transition-colors" />
          </button>
        )}
      </div>
    </div>
  );
};

export default MovieRow;
