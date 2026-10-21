import React, { useMemo } from 'react';
import { Filter, Layers, LayoutGrid, X, Sparkles, Check } from 'lucide-react';
import { getPlatformConfig } from './MovieCard';

// Lista de proveedores oficiales soportados con su identidad visual
export const OTT_PLATFORMS = [
  { key: 'all', label: 'Todos', shortLabel: 'Todos', isAll: true },
  { key: 'local', label: 'FlixHN (Local)', shortLabel: 'FlixHN', isLocal: true },
  { key: 'netflix', label: 'Netflix', shortLabel: 'Netflix' },
  { key: 'disney', label: 'Disney+', shortLabel: 'Disney+' },
  { key: 'hbo_max', label: 'HBO Max', shortLabel: 'HBO Max' },
  { key: 'prime', label: 'Prime Video', shortLabel: 'Prime' },
];

// Géneros cinematográficos comunes como fallback
const DEFAULT_POPULAR_GENRES = [
  'Acción',
  'Aventura',
  'Animación',
  'Ciencia Ficción',
  'Comedia',
  'Crimen',
  'Documentales',
  'Drama',
  'Fantasía',
  'Infantiles',
  'Misterio',
  'Romance',
  'Suspenso',
  'Terror',
];

const CatalogFilterBar = ({
  selectedPlatform = 'all',
  onSelectPlatform,
  selectedGenre = 'all',
  onSelectGenre,
  availableGenres = [],
  totalCount = 0,
  viewMode = 'rows',
  onToggleViewMode,
  onResetFilters,
  currentTab = 'home',
}) => {
  // Consolidar lista de géneros disponibles reales
  const genreList = useMemo(() => {
    if (availableGenres && availableGenres.length > 0) {
      return availableGenres;
    }
    return DEFAULT_POPULAR_GENRES;
  }, [availableGenres]);

  const isFiltering = selectedPlatform !== 'all' || selectedGenre !== 'all';
  const activePlatformCfg = getPlatformConfig(selectedPlatform);

  return (
    <div className="w-full select-none transition-all duration-300">
      {/* Contenedor Principal con Glassmorphism */}
      <div className="bg-white/80 dark:bg-[#181818]/90 backdrop-blur-xl border border-zinc-200 dark:border-zinc-800/80 rounded-2xl p-3.5 sm:p-4 shadow-xl space-y-3.5 transition-all">
        {/* FILA 1: Barra de Plataformas OTT */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
          <div className="flex items-center gap-2 text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
            <Sparkles className="w-4 h-4 text-[#E50914]" />
            <span>Plataforma:</span>
          </div>

          {/* Botones de Filtro Rápido por Plataforma */}
          <div className="flex items-center gap-1.5 sm:gap-2 overflow-x-auto no-scrollbar py-0.5 scroll-smooth">
            {OTT_PLATFORMS.map((plat) => {
              const isSelected = selectedPlatform === plat.key;
              const cfg = getPlatformConfig(plat.key);

              if (plat.isAll) {
                return (
                  <button
                    key={plat.key}
                    type="button"
                    onClick={() => onSelectPlatform?.(plat.key)}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all cursor-pointer whitespace-nowrap active:scale-95 ${
                      isSelected
                        ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 shadow-md ring-2 ring-zinc-400 dark:ring-white/40'
                        : 'bg-zinc-200/70 dark:bg-zinc-800/70 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-300 dark:hover:bg-zinc-700 border border-zinc-300/50 dark:border-zinc-700/50'
                    }`}
                  >
                    <span>{plat.label}</span>
                  </button>
                );
              }

              return (
                <button
                  key={plat.key}
                  type="button"
                  onClick={() => onSelectPlatform?.(plat.key)}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all cursor-pointer whitespace-nowrap active:scale-95 border ${
                    isSelected
                      ? cfg.activePill
                      : `${cfg.pillBg} border-transparent`
                  }`}
                >
                  {plat.isLocal ? (
                    <span className="w-2 h-2 rounded-full bg-[#E50914] animate-pulse" />
                  ) : (
                    <span
                      className="w-2 h-2 rounded-full"
                      style={{ backgroundColor: cfg.color }}
                    />
                  )}
                  <span>{plat.label}</span>
                </button>
              );
            })}
          </div>

          {/* Selector de Modo de Vista (Carruseles / Grid) */}
          {onToggleViewMode && (
            <div className="hidden lg:flex items-center gap-1 bg-zinc-200 dark:bg-zinc-900 p-1 rounded-xl border border-zinc-300/60 dark:border-zinc-800 self-end md:self-auto">
              <button
                type="button"
                onClick={() => onToggleViewMode('rows')}
                title="Vista en Carruseles de Categoría"
                className={`flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                  viewMode === 'rows'
                    ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm'
                    : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-white'
                }`}
              >
                <Layers className="w-3.5 h-3.5" />
                <span>Filas</span>
              </button>
              <button
                type="button"
                onClick={() => onToggleViewMode('grid')}
                title="Vista en Cuadrícula Completa"
                className={`flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                  viewMode === 'grid'
                    ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm'
                    : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-white'
                }`}
              >
                <LayoutGrid className="w-3.5 h-3.5" />
                <span>Cuadrícula</span>
              </button>
            </div>
          )}
        </div>

        {/* FILA 2: Filtros de Género / Categoría Cinematográfica */}
        <div className="pt-2 border-t border-zinc-200/70 dark:border-zinc-800/80 flex flex-col md:flex-row md:items-center justify-between gap-2.5">
          <div className="flex items-center gap-2 text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider flex-shrink-0">
            <Filter className="w-3.5 h-3.5 text-zinc-400" />
            <span>Género:</span>
          </div>

          {/* Carrusel Desplazable de Géneros */}
          <div className="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-0.5 scroll-smooth flex-1">
            <button
              type="button"
              onClick={() => onSelectGenre?.('all')}
              className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-all cursor-pointer whitespace-nowrap active:scale-95 ${
                selectedGenre === 'all'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'bg-zinc-200/60 dark:bg-zinc-800/50 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-300 dark:hover:bg-zinc-700'
              }`}
            >
              Todos los Géneros
            </button>

            {genreList.map((g) => {
              const isSelected =
                selectedGenre.toLowerCase() === g.toLowerCase();
              return (
                <button
                  key={g}
                  type="button"
                  onClick={() => onSelectGenre?.(g)}
                  className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-all cursor-pointer whitespace-nowrap active:scale-95 ${
                    isSelected
                      ? 'bg-[#E50914] text-white shadow ring-1 ring-red-400/50'
                      : 'bg-zinc-200/60 dark:bg-zinc-800/50 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-300 dark:hover:bg-zinc-700'
                  }`}
                >
                  {g}
                </button>
              );
            })}
          </div>

          {/* Botón de Limpiar Filtros cuando hay filtros activos */}
          {isFiltering && (
            <button
              type="button"
              onClick={onResetFilters}
              className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors border border-red-500/30 cursor-pointer self-start md:self-auto flex-shrink-0"
            >
              <X className="w-3 h-3 stroke-[2.5]" />
              <span>Limpiar filtros</span>
            </button>
          )}
        </div>
      </div>

      {/* Resumen Informativo si hay un filtro aplicado */}
      {isFiltering && (
        <div className="mt-2.5 px-3 flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400 animate-fadeIn">
          <div className="flex items-center gap-2">
            <span>Filtrando por:</span>
            {selectedPlatform !== 'all' && (
              <span
                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold text-[10px] uppercase border ${activePlatformCfg.badgeClass}`}
              >
                <Check className="w-3 h-3 stroke-[3]" />
                {activePlatformCfg.name}
              </span>
            )}
            {selectedGenre !== 'all' && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold text-[10px] uppercase bg-zinc-200 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 border border-zinc-300 dark:border-zinc-700">
                <Check className="w-3 h-3 stroke-[3]" />
                {selectedGenre}
              </span>
            )}
          </div>

          <span className="font-medium text-[11px]">
            {totalCount} {totalCount === 1 ? 'título encontrado' : 'títulos encontrados'}
          </span>
        </div>
      )}
    </div>
  );
};

export default React.memo(CatalogFilterBar);
