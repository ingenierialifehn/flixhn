import React, { useState, useMemo } from 'react';
import { Film, Tv, Play, Search, Loader2, ExternalLink, ShieldCheck } from 'lucide-react';
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
          Max
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
    case 'partner_hls':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-emerald-950/90 text-emerald-400 border border-emerald-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
          HLS Autorizado
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
 * Tarjeta individual del buscador de Browse con distinción clara de disponibilidad y tipo de reproducción.
 */
export const BrowseSearchCard = ({ item, onPlay, onOpenModal }) => {
  const isSeries = (item.type || item.media_type) === 'series';
  const isExternal = Boolean(
    item.playback_type === 'OTT-EXTERNAL' ||
    item.requires_external_app ||
    (typeof item.id === 'string' && /^(nflx_|disney_|max_)/.test(item.id))
  );
  const isHlsAuthorized = item.playback_type === 'OTT-HLS-AUTHORIZED';

  const isCompleted = Boolean(
    item.playback_type === 'LOCAL-HLS' ||
    item.is_already_downloaded ||
    item.is_available_locally ||
    item.status === 'En Catálogo' ||
    (!item.is_ott && item.id && !String(item.id).startsWith('ott_'))
  );

  const titleText = item.title || item.name || 'Sin Título';
  const yearText = item.year || item.release_year || '';
  const genreText = item.genre || (isSeries ? 'Serie Original' : 'Largometraje');
  const posterUrl = resolvePosterUrl(item.poster_url || item.image || item.poster);
  const providerKey = item.provider || item.source_platform || 'local';

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

          {/* Badge superior de plataforma (FlixHN, Netflix, Disney+, Max, Prime Video) */}
          <div className="absolute top-2 left-2 z-10 pointer-events-none">
            <PlatformMiniBadge provider={providerKey} />
          </div>

          {/* Badge de tipo de contenido o tipo de reproducción */}
          <div className="absolute top-2 right-2 z-10 pointer-events-none">
            {isExternal ? (
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-950/90 text-amber-300 border border-amber-600/50 backdrop-blur-md">
                <ExternalLink className="w-2.5 h-2.5" /> App Oficial
              </span>
            ) : isHlsAuthorized ? (
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-950/90 text-emerald-300 border border-emerald-600/50 backdrop-blur-md">
                HLS Stream
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-black/75 text-zinc-300 border border-zinc-700/50 backdrop-blur-md">
                {isSeries ? <Tv className="w-2.5 h-2.5" /> : <Film className="w-2.5 h-2.5" />}
                {isSeries ? 'Serie' : 'Película'}
              </span>
            )}
          </div>

          {/* Botón flotante central de Reproducir en hover */}
          <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
            <div className="w-11 h-11 rounded-full bg-[#E50914] text-white flex items-center justify-center shadow-xl transform group-hover:scale-110 transition-transform">
              {isExternal ? (
                <ExternalLink className="w-5 h-5 text-white" />
              ) : (
                <Play className="w-5 h-5 fill-white ml-0.5" />
              )}
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

      {/* Botón de Acción Adaptativo */}
      <div className="mt-3 pt-2 border-t border-zinc-800/80">
        {isExternal ? (
          <button
            type="button"
            onClick={handleClick}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-zinc-800 hover:bg-[#E50914] text-zinc-200 hover:text-white border border-zinc-700/80 hover:border-red-600 transition-all shadow-md active:scale-95 cursor-pointer"
            title="Abrir en la aplicación o portal oficial autorizado"
          >
            <ExternalLink className="w-3.5 h-3.5 shrink-0" />
            <span>App Oficial</span>
          </button>
        ) : isHlsAuthorized ? (
          <button
            type="button"
            onClick={handleClick}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white transition-all shadow-md active:scale-95 cursor-pointer"
            title="Reproducir transmisión HLS autorizada"
          >
            <Play className="w-3.5 h-3.5 fill-white text-white shrink-0" />
            <span>Reproducir HLS</span>
          </button>
        ) : (
          <button
            type="button"
            onClick={handleClick}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-[#E50914] hover:bg-red-700 text-white transition-all shadow-md active:scale-95 cursor-pointer"
            title="Reproducir inmediatamente desde el catálogo local On-Net"
          >
            <Play className="w-3.5 h-3.5 fill-white text-white shrink-0" />
            <span>Reproducir</span>
          </button>
        )}
      </div>
    </div>
  );
};

/**
 * Vista de Búsqueda Unificada para /browse.
 * Integra catálogo local y plataformas OTT autorizadas.
 */
const BrowseSearchGrid = ({
  searchQuery,
  results = [],
  isSearching = false,
  onPlay,
  onOpenModal,
}) => {
  const [typeFilter, setTypeFilter] = useState('all'); // all, movie, series
  const [platformFilter, setPlatformFilter] = useState('all'); // all, netflix, disney, max, amazon_prime, partner_hls, local

  const platformCounts = useMemo(() => {
    const pc = { netflix: 0, disney: 0, max: 0, amazon_prime: 0, partner_hls: 0, local: 0 };
    (results || []).forEach((item) => {
      const p = String(item.provider || item.source_platform || 'local').toLowerCase().trim();
      if (p === 'netflix') pc.netflix++;
      else if (p.includes('disney')) pc.disney++;
      else if (p.includes('max') || p.includes('hbo')) pc.max++;
      else if (p.includes('prime') || p.includes('amazon')) pc.amazon_prime++;
      else if (p.includes('hls') || p.includes('partner')) pc.partner_hls++;
      else pc.local++;
    });
    return pc;
  }, [results]);

  const filteredResults = useMemo(() => {
    return (results || []).filter((item) => {
      // Filtro por tipo
      const isS = (item.type || item.media_type) === 'series';
      if (typeFilter === 'series' && !isS) return false;
      if (typeFilter === 'movie' && isS) return false;

      // Filtro por plataforma conectada
      if (platformFilter !== 'all') {
        const p = String(item.provider || item.source_platform || 'local').toLowerCase().trim();
        if (platformFilter === 'netflix' && p !== 'netflix') return false;
        if (platformFilter === 'disney' && !p.includes('disney')) return false;
        if (platformFilter === 'max' && !p.includes('max') && !p.includes('hbo')) return false;
        if (platformFilter === 'amazon_prime' && !p.includes('prime') && !p.includes('amazon')) return false;
        if (platformFilter === 'partner_hls' && !p.includes('hls') && !p.includes('partner')) return false;
        if (platformFilter === 'local' && p !== 'local') return false;
      }

      return true;
    });
  }, [results, typeFilter, platformFilter]);

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

      {/* Selector de Plataforma Conectada */}
      <div className="flex flex-wrap items-center gap-2 mb-4">
        <button
          type="button"
          onClick={() => setPlatformFilter('all')}
          className={`px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
            platformFilter === 'all'
              ? 'bg-zinc-800 text-white border border-zinc-600 shadow-md'
              : 'text-zinc-400 hover:text-white bg-zinc-900/60 hover:bg-zinc-800 border border-zinc-800'
          }`}
        >
          Todas las Plataformas ({results.length})
        </button>
        {platformCounts.netflix > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('netflix')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'netflix'
                ? 'bg-[#E50914] text-white border border-red-500 shadow-md'
                : 'text-zinc-300 hover:text-white bg-black/60 hover:bg-red-950/40 border border-red-500/30'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[#E50914]" />
            Netflix ({platformCounts.netflix})
          </button>
        )}
        {platformCounts.disney > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('disney')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'disney'
                ? 'bg-[#0063E5] text-white border border-blue-500 shadow-md'
                : 'text-zinc-300 hover:text-white bg-black/60 hover:bg-blue-950/40 border border-blue-500/30'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[#0063E5]" />
            Disney+ ({platformCounts.disney})
          </button>
        )}
        {platformCounts.max > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('max')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'max'
                ? 'bg-[#5822b4] text-white border border-purple-500 shadow-md'
                : 'text-zinc-300 hover:text-white bg-black/60 hover:bg-purple-950/40 border border-purple-500/30'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[#5822b4]" />
            Max ({platformCounts.max})
          </button>
        )}
        {platformCounts.amazon_prime > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('amazon_prime')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'amazon_prime'
                ? 'bg-[#00A8E1] text-white border border-sky-500 shadow-md'
                : 'text-zinc-300 hover:text-white bg-black/60 hover:bg-sky-950/40 border border-sky-500/30'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[#00A8E1]" />
            Prime Video ({platformCounts.amazon_prime})
          </button>
        )}
        {platformCounts.partner_hls > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('partner_hls')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'partner_hls'
                ? 'bg-emerald-600 text-white border border-emerald-500 shadow-md'
                : 'text-zinc-300 hover:text-white bg-black/60 hover:bg-emerald-950/40 border border-emerald-500/30'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
            HLS Autorizado ({platformCounts.partner_hls})
          </button>
        )}
        {platformCounts.local > 0 && (
          <button
            type="button"
            onClick={() => setPlatformFilter('local')}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all cursor-pointer ${
              platformFilter === 'local'
                ? 'bg-red-900 text-white border border-red-600 shadow-md'
                : 'text-zinc-300 hover:text-white bg-zinc-900/60 hover:bg-zinc-800 border border-zinc-800'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[#E50914]" />
            Catálogo Local FlixHN ({platformCounts.local})
          </button>
        )}
      </div>

      {/* Grid de Resultados */}
      {filteredResults.length > 0 ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 sm:gap-4 md:gap-5">
          {filteredResults.map((item) => (
            <BrowseSearchCard
              key={item.id || item.slug || Math.random()}
              item={item}
              onPlay={onPlay}
              onOpenModal={onOpenModal}
            />
          ))}
        </div>
      ) : (
        <div className="py-20 text-center space-y-3">
          <div className="w-16 h-16 rounded-full bg-zinc-900 border border-zinc-800 flex items-center justify-center mx-auto text-zinc-500">
            <Search className="w-7 h-7" />
          </div>
          <p className="text-zinc-400 text-sm">
            No se encontraron títulos que coincidan con "{searchQuery}".
          </p>
          <p className="text-zinc-500 text-xs">
            Verifique la ortografía o intente con el nombre de otra película, serie o plataforma.
          </p>
        </div>
      )}
    </section>
  );
};

export default BrowseSearchGrid;
