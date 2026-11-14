import React, { useState, useMemo } from 'react';
import {
  Film,
  Tv,
  Check,
  Play,
  DownloadCloud,
  Filter,
  Sparkles,
  Zap,
  Search,
} from 'lucide-react';
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
 * Grid de Resultados de Búsqueda Integrado FlixGrab idéntico a la imagen de referencia.
 * Muestra el contenido local On-Net y los resultados de plataformas conectadas
 * con carátulas verticales, badges de origen, tipo, año, sinopsis y botones semánticos.
 */
const FlixGrabSearchGrid = ({
  searchQuery,
  results = [],
  onPlay,
  onOpenModal,
}) => {
  const [typeFilter, setTypeFilter] = useState('all'); // all, movie, series
  const [platformFilter, setPlatformFilter] = useState('all'); // all, local, netflix, disney, etc.

  // Filtrado reactivo en cliente para respuesta instantánea de las pestañas
  const filteredResults = useMemo(() => {
    return (results || []).filter((item) => {
      // 1. Filtro por tipo de medio (Películas / Series)
      if (typeFilter !== 'all') {
        const itemType = String(item.type || item.media_type || '').toLowerCase();
        if (typeFilter === 'movie' && itemType !== 'movie') return false;
        if (typeFilter === 'series' && itemType !== 'series') return false;
      }

      // 2. Filtro por plataforma (FlixHN, Netflix, Disney, etc.)
      if (platformFilter !== 'all') {
        const prov = String(item.provider || item.source_platform || 'local').toLowerCase();
        if (platformFilter === 'local' && prov !== 'local' && !item.is_already_downloaded) {
          return false;
        }
        if (platformFilter !== 'local') {
          if (!prov.includes(platformFilter)) {
            return false;
          }
        }
      }

      return true;
    });
  }, [results, typeFilter, platformFilter]);

  return (
    <section className="px-4 sm:px-6 md:px-14 py-6 max-w-[1920px] mx-auto animate-fadeIn select-none">
      {/* 1. Cabecera Estilo Buscador Integrado FlixGrab */}
      <div className="space-y-4 mb-6 border-b border-zinc-800/80 pb-5">
        {/* Badges de tecnología */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-red-600/15 text-red-400 border border-red-500/30">
            <span className="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
            FlixGrab Engine On-Demand
          </span>
          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-emerald-600/15 text-emerald-400 border border-emerald-500/30">
            <Zap className="w-3 h-3 text-emerald-400" />
            Ingesta Fibra Óptica
          </span>
        </div>

        {/* Título Principal y Contador */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-black text-white flex items-center gap-2 tracking-tight">
              <Search className="w-6 h-6 text-[#E50914]" />
              Buscador Integrado FlixGrab
            </h1>
            <p className="text-xs sm:text-sm text-zinc-400 mt-1 max-w-3xl leading-relaxed">
              Resultados integrados para <span className="text-white font-bold">"{searchQuery}"</span> en catálogo local On-Net y plataformas vinculadas (Netflix, Disney+, Max o Prime Video).
            </p>
          </div>

          <div className="text-right">
            <span className="text-xs text-zinc-400 font-medium">Resultados encontrados: </span>
            <span className="text-sm font-black text-white font-mono">{filteredResults.length} títulos</span>
          </div>
        </div>

        {/* Barra de Filtros: Tipo (Todos, Películas, Series) y Filtro de Plataforma */}
        <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
          {/* Filtro por Tipo */}
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold uppercase tracking-wider text-zinc-500 mr-1">TIPO:</span>
            <button
              type="button"
              onClick={() => setTypeFilter('all')}
              className={`px-3 py-1 rounded-md text-xs font-bold transition-all cursor-pointer ${
                typeFilter === 'all'
                  ? 'bg-zinc-800 text-white border border-zinc-600 shadow'
                  : 'text-zinc-400 hover:text-white hover:bg-zinc-900 border border-transparent'
              }`}
            >
              Todos
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
              Películas
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
              Series
            </button>
          </div>

          {/* Selector de Plataforma */}
          <div className="flex items-center gap-2">
            <div className="relative">
              <select
                value={platformFilter}
                onChange={(e) => setPlatformFilter(e.target.value)}
                className="bg-[#181818] text-xs font-bold text-zinc-300 border border-zinc-700/80 rounded-lg px-3 py-1.5 pr-8 hover:border-zinc-500 focus:outline-none focus:border-red-500 cursor-pointer transition-colors"
              >
                <option value="all">Todas las Plataformas OTT</option>
                <option value="local">FlixHN (Catálogo Local)</option>
                <option value="netflix">Netflix</option>
                <option value="disney">Disney+</option>
                <option value="hbo_max">HBO Max</option>
                <option value="prime">Prime Video</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      {/* 2. Grid de Tarjetas Estilo Buscador Integrado FlixGrab */}
      {filteredResults.length === 0 ? (
        <div className="py-24 text-center space-y-3">
          <Search className="w-10 h-10 text-zinc-600 mx-auto" />
          <p className="text-base text-zinc-400 font-semibold">
            No se encontraron títulos que coincidan con "{searchQuery}" para los filtros seleccionados.
          </p>
          <p className="text-xs text-zinc-500">
            Prueba seleccionando "Todos" en el tipo o "Todas las Plataformas OTT".
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {filteredResults.map((item) => {
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
            const overviewText = item.overview || item.description || 'Disponible para transmisión On-Net.';
            const posterUrl = resolvePosterUrl(item.poster_url || item.image || item.poster);

            return (
              <div
                key={item.id || titleText}
                className="bg-[#141414] border border-zinc-800/90 hover:border-zinc-700 rounded-xl p-3 flex flex-col justify-between transition-all duration-200 group shadow-lg hover:shadow-black/70 relative overflow-hidden"
              >
                <div>
                  {/* Carátula vertical con badges de plataforma y tipo */}
                  <div
                    onClick={() => {
                      if (isCompleted) {
                        if (onPlay) onPlay(item);
                      } else {
                        if (onPlay) onPlay(item);
                      }
                    }}
                    className="relative aspect-[2/3] w-full rounded-lg overflow-hidden bg-zinc-900 border border-zinc-800/80 shadow-inner group cursor-pointer"
                  >
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

                    {/* Badge superior de plataforma */}
                    <div className="absolute top-2 left-2 z-10 pointer-events-none">
                      <PlatformMiniBadge provider={isCompleted ? 'local' : (item.provider || item.source_platform)} />
                    </div>

                    {/* Badge de Serie/Película */}
                    <div className="absolute top-2 right-2 z-10 pointer-events-none">
                      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-black/70 text-zinc-300 border border-zinc-700/50 backdrop-blur-md">
                        {isSeries ? <Tv className="w-2.5 h-2.5" /> : <Film className="w-2.5 h-2.5" />}
                        {isSeries ? 'Serie' : 'Película'}
                      </span>
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

                  {/* Metadatos: Título, Género y Resumen Breve */}
                  <div className="mt-2.5 space-y-1">
                    <h3
                      className="font-bold text-xs text-white truncate leading-snug group-hover:text-red-400 transition-colors"
                      title={titleText}
                    >
                      {titleText}
                    </h3>
                    <div className="text-[11px] text-zinc-400 truncate font-medium">
                      {genreText}
                    </div>
                    <p className="text-[11px] text-zinc-500 line-clamp-2 leading-relaxed">
                      {overviewText}
                    </p>
                  </div>
                </div>

                {/* Botones de Acción Semánticos según Estado (Idénticos a la Imagen 2) */}
                <div className="mt-3 pt-2 border-t border-zinc-800/80 space-y-1.5">
                  {isCompleted ? (
                    // Estado 1: En Catálogo Local
                    <div className="space-y-1.5">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          if (onOpenModal) onOpenModal(item);
                          else if (onPlay) onPlay(item);
                        }}
                        className="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-lg text-xs font-bold bg-emerald-600/25 hover:bg-emerald-600/35 text-emerald-300 border border-emerald-500/40 transition-all shadow-sm active:scale-95 cursor-pointer"
                        title="Título disponible en catálogo On-Net."
                      >
                        <Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                        <span className="truncate">✓ En Catálogo</span>
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          if (onPlay) onPlay(item);
                        }}
                        className="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-lg text-[11px] font-bold bg-sky-950/80 hover:bg-sky-900/90 text-sky-300 border border-sky-500/40 transition-all shadow-sm active:scale-95 cursor-pointer"
                        title="Reproducción directa On-Net desde el nodo perimetral"
                      >
                        <Play className="w-3 h-3 fill-sky-400 text-sky-400 shrink-0" />
                        <span className="truncate">▶ Reproducción Local On-Net</span>
                      </button>
                    </div>
                  ) : (
                    // Estado 2: Disponible para Descarga e Ingesta On-Demand con FlixGrab
                    <div className="grid grid-cols-1 gap-1.5">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          if (onPlay) onPlay(item);
                        }}
                        className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold transition-all shadow-md shadow-red-950/40 bg-gradient-to-r from-[#E50914] to-orange-600 hover:from-red-600 hover:to-orange-500 text-white active:scale-95 cursor-pointer"
                        title="Descarga automática con FlixGrab Engine y reproducción inmediata"
                      >
                        <DownloadCloud className="w-3.5 h-3.5 text-white shrink-0" />
                        <span className="truncate">+ FlixGrab / Descargar</span>
                      </button>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </section>
  );
};

export default FlixGrabSearchGrid;

