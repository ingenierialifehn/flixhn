import React from 'react';
import { Play, Info } from 'lucide-react';

const Billboard = ({ title, onPlay, onOpenModal }) => {
  if (!title) return null;

  return (
    <div className="relative h-[65vh] md:h-[82vh] w-full text-white select-none">
      {/* Imagen de Fondo (Backdrop) */}
      <div className="absolute inset-0">
        <img
          src={title.backdrop_url || title.poster_url}
          alt={title.name}
          onError={(e) => {
            e.currentTarget.onerror = null;
            e.currentTarget.src = `/api/media/backdrop?title=${encodeURIComponent(title.name || 'FlixHN')}`;
          }}
          className="w-full h-full object-cover object-center filter brightness-90"
        />
        {/* Degradado lateral izquierdo cinematográfico */}
        <div className="absolute inset-0 gradient-billboard" />
        {/* Degradado inferior hacia el fondo negro #141414 de las filas */}
        <div className="absolute inset-0 gradient-bottom-vignette" />
      </div>

      {/* Contenido del Billboard */}
      <div className="absolute bottom-16 md:bottom-28 left-4 md:left-12 max-w-2xl z-10 space-y-4">
        {/* Distintivo de Contenido Local */}
        <div className="flex items-center gap-2">
          <span className="bg-[#E50914] text-white font-black text-[10px] md:text-xs px-2 py-0.5 rounded tracking-wider uppercase shadow-md">
            ORIGINAL ISP ON-NET
          </span>
          <span className="text-xs text-gray-300 font-semibold border border-gray-600 px-1.5 py-0.5 rounded">
            ULTRA HD 4K
          </span>
          {title.release_year > 1900 && (
            <span className="text-xs text-gray-300 font-medium">
              {title.release_year}
            </span>
          )}
          <span className="text-xs text-gray-400 font-medium">
            • {title.genre}
          </span>
        </div>

        {/* Título Monumental */}
        <h1 className="font-display text-4xl sm:text-6xl md:text-7xl font-black tracking-wide leading-none text-shadow-netflix">
          {title.name}
        </h1>

        {/* Sinopsis concisa */}
        <p className="text-sm md:text-base text-gray-200 line-clamp-3 text-shadow-netflix max-w-xl font-normal leading-relaxed">
          {title.description}
        </p>

        {/* Botones de Acción */}
        <div className="flex items-center gap-3 pt-2">
          <button
            onClick={() => onPlay(title)}
            className="flex items-center justify-center gap-2 bg-white text-black font-bold px-6 py-2.5 md:px-8 md:py-3 rounded hover:bg-white/80 active:scale-95 transition-all text-sm md:text-base shadow-xl"
          >
            <Play className="w-5 h-5 fill-black" />
            Reproducir
          </button>

          <button
            onClick={() => onOpenModal(title)}
            className="flex items-center justify-center gap-2 bg-gray-500/50 backdrop-blur-md text-white font-semibold px-5 py-2.5 md:px-7 md:py-3 rounded hover:bg-gray-500/30 active:scale-95 transition-all text-sm md:text-base shadow-lg border border-white/10"
          >
            <Info className="w-5 h-5" />
            Más información
          </button>
        </div>
      </div>
    </div>
  );
};

export default Billboard;
