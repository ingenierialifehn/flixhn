import React, { useState, useMemo } from 'react';
import { Film, Tv, Play, Search, Loader2 } from 'lucide-react';
import { resolvePosterUrl } from '../utils/media';

/**
 * Insignia visual distintiva de cada plataforma OTT y catálogo local FlixHN
 */
export const PlatformMiniBadge = ({ provider }) => {
  const p = String(provider || 'local').toLowerCase().trim();

  switch (p) {
    case 'netflix':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-black/90 text-[#E50914] border border-red-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-[#E50914]" />
          Netflix
        </span>
      );
    case 'disney':
    case 'disneyplus':
    case 'disney_plus':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-[#0c1b33]/90 text-blue-400 border border-blue-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-blue-400" />
          Disney+
        </span>
      );
    case 'hbo_max':
    case 'max':
    case 'hbo':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-[#5822b4]/40 text-purple-300 border border-purple-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-purple-400" />
          HBO Max
        </span>
      );
    case 'amazon_prime':
    case 'prime_video':
    case 'prime':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-sky-950/90 text-[#00A8E1] border border-sky-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-[#00A8E1]" />
          Prime Video
        </span>
      );
    case 'apple_tv':
    case 'apple':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-zinc-900/90 text-zinc-200 border border-zinc-700/60 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-300" />
          Apple TV+
        </span>
      );
    case 'paramount':
    case 'paramount_plus':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-blue-950/80 text-blue-400 border border-blue-600/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
          Paramount+
        </span>
      );
    case 'local':
    case 'flixhn':
    default:
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-black/90 text-red-400 border border-red-500/50 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-[#E50914] animate-pulse" />
          FlixHN
        </span>
      );
  }
};

/**
 * Tarjeta individual del buscador de Browse.
 * Estilo visual vertical cinematográfico idéntico a la referencia de plataformas conectadas.
 * Acción unificada y transparente: solo un clic en "Ver" o "Reproducir".
 */
export const BrowseSearchCard = ({ item, onPlay, onOpenModal }) => {
  const isSeries = (item.type || item.media_type) === 'series';
  const isCompleted = Boolean(
    item.is_already_downloaded ||
    item.is_available_locally ||
    item.status === 'En Catálogo' ||
    (!item.is_ott && item.id && !String(item.id).startsWith('ott_'))
  );

  const titleText = item.title || item.name || 'Sin Título';
  const yearText = item.year || item.release_year || '';
  const genreText = item.genre || (isSeries ? 'Serie Original' : 'Largometraje');
  const posterUrl = resolvePosterUrl(item.poster_url || item.image || item.poster);
  const providerKey = isCompleted ? 'local' : (item.provider || item.source_platform || 'local');

  const handleClick = (e) => {
    e.stopPropagation();
    if (onPlay) {
      onPlay(item);
    }
  };

  return (
    <div
      onClick={handleClick}
      className="bg-[#141414] border border-zinc-800/90 hover:border-zinc-700 rounded-xl p-2.5 sm:p-3 flex flex-col justify-between transition-all duration-200 group shadow-lg hover:shadow-black/70 relative overflow-hidden cursor-pointer select-none"
    >
      <div>
        {/* Carátula vertical (aspect-ratio 2/3) con badges integrados */}
        <div className="relative aspect-[2/3] w-full rounded-lg overflow-hidden bg-zinc-900 border border-zinc-800/80 shadow-inner group">
          <img
            src={posterUrl}
            alt={titleText}
            loading="lazy"
            className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
            onError={(e) => {
              e.target.onerror = null;
              e.target.src = `/api/media/poster?title=${encodeURIComponent(titleText)}`;
            }}
          />

          {/* Gradientes cinematográficos */}
          <div className="absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-black/80 via-black/40 to-transparent pointer-events-none" />
          <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/90 via-black/40 to-transparent pointer-events-none" />

          {/* Badge superior de plataforma (FlixHN, Netflix, Disney+, HBO Max, Prime Video) */}
          <div className="absolute top-2 left-2 z-10 pointer-events-none">
            <PlatformMiniBadge provider={providerKey} />
          </div>

          {/* Badge de tipo de contenido (Serie / Película) */}
          <div className="absolute top-2 right-2 z-10 pointer-events-none">
            <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-black/75 text-zinc-300 border border-zinc-700/50 backdrop-blur-md">
              {isSeries ? <Tv className="w-2.5 h-2.5" /> : <Film className="w-2.5 h-2.5" />}
              {isSeries ? 'Serie' : 'Película'}
            </span>
          </div>

          {/* Botón flotante central de Reproducir en hover */}
          <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
            <div className="w-11 h-11 rounded-full bg-[#E50914] text-white flex items-center justify-center shadow-xl transform group-hover:scale-110 transition-transform">
              <Play className="w-5 h-5 fill-white ml-0.5" />
            </div>
          </div>

          {/* Año en esquina inferior derecha */}
          {yearText && (
            <div className="absolute bottom-2 right-2 z-10 pointer-events-none">
              <span className="text-[10px] font-mono text-zinc-300 bg-black/80 px-1.5 py-0.5 rounded border border-zinc-800/50">
                {yearText}
              </span>
            </div>
          )}
        </div>

        {/* Metadatos: Título y Género */}
        <div className="mt-2.5 space-y-1">
          <h3
            className="font-bold text-xs sm:text-sm text-white truncate leading-snug group-hover:text-red-400 transition-colors"
            title={titleText}
          >
            {titleText}
          </h3>
          <div className="text-[11px] text-zinc-400 truncate font-medium">
            {genreText}
          </div>
        </div>
      </div>

      {/* Botón de Acción Limpio: "Reproducir" o "Ver" */}
      <div className="mt-3 pt-2 border-t border-zinc-800/80">
        {isCompleted ? (
          <button
            type="button"
            onClick={handleClick}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-[#E50914] hover:bg-red-700 text-white transition-all shadow-md active:scale-95 cursor-pointer"
            title="Reproducir inmediatamente desde el almacenamiento local"
          >
            <Play className="w-3.5 h-3.5 fill-white text-white shrink-0" />
            <span>Reproducir</span>
          </button>
        ) : (
          <button
            type="button"
            onClick={handleClick}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-zinc-800 hover:bg-[#E50914] text-white border border-zinc-700/80 hover:border-red-600 transition-all shadow-md active:scale-95 cursor-pointer"
            title="Cargar y descargar automáticamente al servidor para ver ahora"
          >
            <Play className="w-3.5 h-3.5 fill-white text-white shrink-0" />
            <span>Ver</span>
          </button>
        )}
      </div>
    </div>
  );
};

/**
 * Vista de Búsqueda Completa para /browse.
 * Cabecera limpia estilo Netflix (Resultados para: "...", total títulos)
 * sin badges técnicos ni marcas administrativas.
 * Muestra tanto títulos locales del catálogo como de plataformas OTT conectadas.
 */
const BrowseSearchGrid = ({
  searchQuery,
  results = [],
  isSearching = false,
  onPlay,
  onOpenModal,
}) => {
  const [typeFilter, setTypeFilter] = useState('all'); // all, movie, series

  const counts = useMemo(() => {
    let movies = 0;
    let series = 0;
    (results || []).forEach((item) => {
      const isS = (item.type || item.media_type) === 'series';
      if (isS) series++;
      else movies++;
    });
    return { all: (results || []).length, movies, series };
  }, [results]);

  const filteredResults = useMemo(() => {
    return (results || []).filter((item) => {
      if (typeFilter === 'all') return true;
      const isS = (item.type || item.media_type) === 'series';
      if (typeFilter === 'series') return isS;
      if (typeFilter === 'movie') return !isS;
      return true;
    });
  }, [results, typeFilter]);

  return (
    <section className="pt-24 px-4 sm:px-6 md:px-12 pb-16 min-h-[75vh] max-w-[1920px] mx-auto animate-fadeIn select-none">
      {/* 1. Cabecera limpia estilo Netflix */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 dark:border-zinc-800 pb-4">
        <div>
          <h1 className="text-xl md:text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <span>Resultados para:</span>
            <span className="text-[#E50914]">"{searchQuery}"</span>
          </h1>
        </div>

        <div className="flex items-center gap-3">
          {isSearching && (
            <span className="inline-flex items-center gap-1.5 text-xs text-zinc-400">
              <Loader2 className="w-3.5 h-3.5 animate-spin text-[#E50914]" />
              Buscando en catálogo y plataformas...
            </span>
          )}
          <span className="text-xs text-zinc-500 font-medium">
            {filteredResults.length} {filteredResults.length === 1 ? 'título' : 'títulos'}
          </span>
        </div>
      </div>

      {/* 2. Filtros sutiles por tipo si existen múltiples tipos */}
      {counts.movies > 0 && counts.series > 0 && (
        <div className="flex items-center gap-2 mb-6">
          <button
            type="button"
            onClick={() => setTypeFilter('all')}
            className={`px-3 py-1 rounded-md text-xs font-bold transition-all cursor-pointer ${
              typeFilter === 'all'
                ? 'bg-zinc-800 text-white border border-zinc-600 shadow'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-900 border border-transparent'
            }`}
          >
            Todos ({counts.all})
          </button>
          <button
            type="button"
            onClick={() => setTypeFilter('movie')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-bold transition-all cursor-pointer ${
              typeFilter === 'movie'
                ? 'bg-zinc-800 text-white border border-zinc-600 shadow'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-900 border border-transparent'
            }`}
          >
            <Film className="w-3.5 h-3.5" />
            Películas ({counts.movies})
          </button>
          <button
            type="button"
            onClick={() => setTypeFilter('series')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-bold transition-all cursor-pointer ${
              typeFilter === 'series'
                ? 'bg-zinc-800 text-white border border-zinc-600 shadow'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-900 border border-transparent'
            }`}
          >
            <Tv className="w-3.5 h-3.5" />
            Series ({counts.series})
          </button>
        </div>
      )}

      {/* 3. Grid de Resultados */}
      {filteredResults.length === 0 ? (
        <div className="py-24 text-center space-y-3">
          {isSearching ? (
            <div className="flex flex-col items-center justify-center space-y-3">
              <div className="w-10 h-10 border-3 border-[#E50914] border-t-transparent rounded-full animate-spin" />
              <p className="text-sm text-zinc-400">Buscando en catálogo local y plataformas vinculadas...</p>
            </div>
          ) : (
            <>
              <Search className="w-10 h-10 text-zinc-600 mx-auto" />
              <p className="text-base text-zinc-600 dark:text-zinc-400 font-semibold">
                No se encontraron títulos que coincidan con "{searchQuery}".
              </p>
              <p className="text-xs text-zinc-500">
                Prueba con otro título de Netflix, Disney+, HBO Max o Prime Video.
              </p>
            </>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4 md:gap-5">
          {filteredResults.map((item) => (
            <BrowseSearchCard
              key={item.id || item.title || item.name}
              item={item}
              onPlay={onPlay}
              onOpenModal={onOpenModal}
            />
          ))}
        </div>
      )}
    </section>
  );
};

export default BrowseSearchGrid;

