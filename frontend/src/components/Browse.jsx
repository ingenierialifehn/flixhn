import React, { useState, useEffect, useCallback, useMemo } from 'react';
import Navbar from './Navbar';
import Billboard from './Billboard';
import MovieRow from './MovieRow';
import MovieCard from './MovieCard';
import DetailModal from './DetailModal';
import CatalogFilterBar from './CatalogFilterBar';
import { HardDrive, RefreshCw, Settings, Film } from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';

const Browse = ({ onPlay, onNavigateToAdmin }) => {
  const { activeProfile, user, loading: authLoading } = useAuth();
  const { t } = useLanguage();
  const [currentTab, setCurrentTab] = useState('home');
  const [billboardTitle, setBillboardTitle] = useState(null);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalTitleId, setModalTitleId] = useState(null);
  const [isSyncing, setIsSyncing] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);

  // Estados de filtros rápidos por plataforma OTT y género
  const [selectedPlatform, setSelectedPlatform] = useState('all');
  const [selectedGenre, setSelectedGenre] = useState('all');
  const [viewMode, setViewMode] = useState('rows'); // 'rows' | 'grid'

  // Estados de búsqueda reactiva estilo Netflix
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState([]);

  const fetchCatalog = useCallback(async () => {
    if (!activeProfile?.id) {
      setLoading(false);
      return;
    }

    setLoading(true);
    try {
      const res = await api.get(`/titles?profile_id=${activeProfile.id}`);
      if (res.data) {
        setBillboardTitle(res.data.billboard || null);
        setRows(res.data.rows || []);
      } else {
        setBillboardTitle(null);
        setRows([]);
      }
    } catch (err) {
      console.error('Error al cargar catálogo en Browse.jsx:', err);
      setBillboardTitle(null);
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [activeProfile?.id]);

  useEffect(() => {
    fetchCatalog();
  }, [fetchCatalog]);

  const handleSearchResults = useCallback((results, query) => {
    setSearchResults(results || []);
    setSearchQuery(query || '');
  }, []);

  const handleSearchQueryChange = useCallback((query) => {
    setSearchQuery(query || '');
  }, []);

  const handleTabChange = useCallback((tab) => {
    setSearchQuery('');
    setSearchResults([]);
    setCurrentTab(tab);
  }, []);

  const handleRemoveFromContinueWatching = async (titleId, episodeId = null) => {
    setRows((prevRows) =>
      prevRows.map((row) => {
        if (row.id === 'continue_watching' || row.is_progress_row) {
          return {
            ...row,
            items: (row.items || []).filter((item) => {
              const itTitleId = item.title_id || item.title?.id || item.id;
              if (episodeId && (item.episode_id || item.episode?.id)) {
                const itEpId = item.episode_id || item.episode?.id;
                return itEpId !== episodeId;
              }
              return itTitleId !== titleId;
            }),
          };
        }
        return row;
      })
    );

    try {
      await api.delete(`/watch-history/${titleId}`, {
        params: {
          profile_id: activeProfile?.id,
          episode_id: episodeId,
        },
      });
    } catch (err) {
      console.error('Error al quitar de continuar viendo:', err);
      fetchCatalog();
    }
  };

  const handleSyncMedia = async () => {
    setIsSyncing(true);
    setSyncStatus(null);
    try {
      const res = await api.post('/admin/media/sync');
      if (res.data?.success) {
        setSyncStatus({
          type: 'success',
          message: res.data.message || `Catálogo actualizado: ${res.data.total_synced} títulos disponibles.`,
        });
      } else {
        setSyncStatus({
          type: res.data?.status === 'unauthorized' ? 'warning' : 'error',
          message: res.data?.message || 'Aviso al conectar con el servidor de medios.',
        });
      }
      await fetchCatalog();
    } catch (e) {
      console.warn('Error durante la sincronización:', e);
      setSyncStatus({
        type: 'error',
        message: e.response?.data?.message || 'Error de conexión con el servidor de medios configurado.',
      });
      await fetchCatalog();
    } finally {
      setIsSyncing(false);
      setTimeout(() => setSyncStatus(null), 6000);
    }
  };

  const handleResetFilters = useCallback(() => {
    setSelectedPlatform('all');
    setSelectedGenre('all');
  }, []);

  // Extraer géneros dinámicos disponibles en el catálogo
  const availableGenres = useMemo(() => {
    const genreSet = new Set();
    (rows || []).forEach((row) => {
      if (
        row?.title &&
        !['Películas', 'Series', 'Aclamados y Destacados FlixHN', 'Continuar Viendo'].includes(row.title)
      ) {
        genreSet.add(row.title);
      }
      (row.items || []).forEach((item) => {
        const it = item.title || item;
        if (it?.genre) {
          it.genre.split(/[\/,]/).forEach((g) => {
            const clean = g.trim();
            if (clean && clean.length > 2) genreSet.add(clean);
          });
        }
      });
    });
    return Array.from(genreSet).slice(0, 15);
  }, [rows]);

  // Filtrado reactivo multidimensional (Tab + Plataforma OTT + Género)
  const { filteredRows, allFilteredItems } = useMemo(() => {
    let baseRows = rows || [];

    // 1. Filtrar por Tab Principal (Películas / Series / Destacados)
    if (currentTab === 'movies') {
      baseRows = baseRows.filter(
        (r) =>
          r?.id === 'movies_row' ||
          (r?.id !== 'series_row' &&
            (r?.items || []).some(
              (i) => i?.type === 'movie' || i?.title?.type === 'movie'
            ))
      );
    } else if (currentTab === 'series') {
      baseRows = baseRows.filter(
        (r) =>
          r?.id === 'series_row' ||
          (r?.id !== 'movies_row' &&
            (r?.items || []).some(
              (i) => i?.type === 'series' || i?.title?.type === 'series'
            ))
      );
    } else if (currentTab === 'featured') {
      baseRows = baseRows.filter(
        (r) => r?.id === 'featured_row' || r?.id === 'continue_watching'
      );
    }

    // Helper para verificar plataforma OTT
    const matchPlatform = (item) => {
      if (selectedPlatform === 'all') return true;
      const t = item.title || item;
      const prov = String(
        t?.provider || t?.source_platform || item?.provider || item?.source_platform || 'local'
      ).toLowerCase().trim();

      if (selectedPlatform === 'prime') {
        return prov === 'prime' || prov === 'amazon_prime' || prov === 'amazon';
      }
      if (selectedPlatform === 'disney') {
        return prov === 'disney' || prov === 'disney_plus' || prov === 'disney+';
      }
      if (selectedPlatform === 'hbo_max') {
        return prov === 'hbo_max' || prov === 'max' || prov === 'hbo';
      }
      return prov === selectedPlatform;
    };

    // Helper para verificar género
    const matchGenre = (item, rowTitle) => {
      if (selectedGenre === 'all') return true;
      const t = item.title || item;
      const itemGenre = String(t?.genre || '').toLowerCase();
      const targetGenre = selectedGenre.toLowerCase();

      if (itemGenre.includes(targetGenre)) return true;
      if (rowTitle && rowTitle.toLowerCase().includes(targetGenre)) return true;
      return false;
    };

    // Helper para verificar concordancia con el tab activo
    const matchTabType = (item) => {
      if (currentTab === 'movies') {
        return (item.type || item.title?.type) === 'movie';
      }
      if (currentTab === 'series') {
        return (item.type || item.title?.type) === 'series';
      }
      return true;
    };

    const outRows = [];
    const collectedItemsMap = new Map();

    baseRows.forEach((row) => {
      const items = (row.items || []).filter((item) => {
        if (!matchTabType(item)) return false;
        if (!matchPlatform(item)) return false;
        if (!matchGenre(item, row.title)) return false;
        return true;
      });

      if (items.length > 0) {
        outRows.push({
          ...row,
          items,
        });

        items.forEach((it) => {
          const id = it.id || it.title?.id;
          if (id && !collectedItemsMap.has(id)) {
            collectedItemsMap.set(id, it);
          }
        });
      }
    });

    return {
      filteredRows: outRows,
      allFilteredItems: Array.from(collectedItemsMap.values()),
    };
  }, [rows, currentTab, selectedPlatform, selectedGenre]);

  const hasContent = Boolean(billboardTitle) || (rows || []).some((r) => r?.items && r.items.length > 0);
  const isSearchActive = searchQuery.trim().length > 0;

  if (authLoading) {
    return (
      <div className="min-h-screen bg-[#141414] flex flex-col items-center justify-center space-y-4">
        <span className="font-display text-5xl md:text-6xl text-[#E50914] font-black tracking-widest animate-pulse">
          FLIXHN
        </span>
        <div className="w-8 h-8 border-3 border-red-600 border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-100 dark:bg-[#141414] text-gray-900 dark:text-white flex flex-col justify-between selection:bg-[#E50914] selection:text-white transition-colors duration-200">
      <Navbar
        currentTab={currentTab}
        setCurrentTab={handleTabChange}
        onPlay={(t, ep, time) => onPlay?.(t, ep, time)}
        onOpenModal={(t) => setModalTitleId(t?.id)}
        onSearchResults={handleSearchResults}
        onSearchQueryChange={handleSearchQueryChange}
      />

      {/* Notificación flotante de sincronización */}
      {syncStatus && (
        <div className="fixed top-20 right-6 z-50 animate-fadeIn">
          <div
            className={`px-4 py-3 rounded-xl border text-xs font-semibold shadow-xl flex items-center gap-2.5 backdrop-blur-md ${
              syncStatus.type === 'success'
                ? 'bg-emerald-500/90 text-white border-emerald-400'
                : syncStatus.type === 'warning'
                ? 'bg-amber-500/90 text-white border-amber-400'
                : 'bg-red-600/90 text-white border-red-500'
            }`}
          >
            <span>{syncStatus.message}</span>
            <button
              onClick={() => setSyncStatus(null)}
              className="text-white hover:underline text-[11px] font-bold ml-2 cursor-pointer"
            >
              ✕
            </button>
          </div>
        </div>
      )}

      {/* Overlay de Loader Limpio durante la Sincronización */}
      {isSyncing && (
        <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm flex flex-col items-center justify-center p-6 animate-fadeIn select-none">
          <div className="bg-white dark:bg-[#181818] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-8 max-w-sm w-full flex flex-col items-center text-center space-y-4 shadow-2xl">
            <div className="relative">
              <div className="w-14 h-14 border-4 border-[#E50914] border-t-transparent rounded-full animate-spin" />
              <div className="absolute inset-0 flex items-center justify-center">
                <HardDrive className="w-6 h-6 text-[#E50914]" />
              </div>
            </div>
            <div className="space-y-1">
              <h3 className="text-base font-bold text-zinc-900 dark:text-white">
                Conectando al servidor y cargando catálogo...
              </h3>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">
                Descargando categorías, títulos, pósters y streams desde la IP configurada.
              </p>
            </div>
            <span className="text-[11px] font-mono uppercase tracking-widest text-[#E50914] font-bold animate-pulse">
              FlixHN DirectPlay Core
            </span>
          </div>
        </div>
      )}

      <main className="flex-1 pb-16">
        {/* VISTA 1: BÚSQUEDA REACTIVA EN GRID ESTILO NETFLIX (IMAGEN 4) */}
        {isSearchActive ? (
          <section className="pt-24 px-4 md:px-12 pb-16 min-h-[75vh] animate-fadeIn">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 dark:border-zinc-800 pb-4">
              <h1 className="text-xl md:text-2xl font-bold text-gray-900 dark:text-white">
                {t('search_results_for', 'Resultados para')}: <span className="text-[#E50914]">"{searchQuery}"</span>
              </h1>
              <span className="text-xs text-zinc-500 font-medium">
                {(searchResults || []).length} {(searchResults || []).length === 1 ? 'título' : 'títulos'}
              </span>
            </div>

            {(searchResults || []).length === 0 ? (
              <div className="py-24 text-center space-y-3">
                <p className="text-base text-zinc-600 dark:text-zinc-400">
                  {t('no_results_found', 'No se encontraron títulos que coincidan con')} "{searchQuery}".
                </p>
                <p className="text-xs text-zinc-500">
                  Sugerencias: prueba con otros términos, actores, directores o géneros.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-5">
                {(searchResults || []).map((item) => (
                  <MovieCard
                    key={item?.id}
                    item={item}
                    onPlay={(t, ep) => onPlay?.(t, ep)}
                    onOpenModal={(t) => setModalTitleId(t?.id)}
                  />
                ))}
              </div>
            )}
          </section>
        ) : (
          /* VISTA 2: CATÁLOGO REGULAR COMPLETO CON BILLBOARD Y TODAS LAS FILAS */
          <>
            {billboardTitle && currentTab === 'home' && (
              <Billboard
                title={billboardTitle}
                onPlay={(t) => onPlay?.(t)}
                onOpenModal={(t) => setModalTitleId(t.id)}
              />
            )}

            {/* Estado Vacío Limpio si no hay contenido aún */}
            {!loading && !hasContent && (
              <div className="min-h-[65vh] flex flex-col items-center justify-center text-center px-4 py-20 space-y-5 animate-fadeIn">
                <div className="w-20 h-20 rounded-2xl bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 flex items-center justify-center text-[#E50914] shadow-md dark:shadow-2xl relative">
                  <HardDrive className="w-10 h-10 stroke-[1.5]" />
                  <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-amber-500 rounded-full border-2 border-white dark:border-[#141414] animate-pulse" />
                </div>

                <div className="space-y-3 max-w-lg">
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-gray-200 dark:bg-zinc-900 text-gray-700 dark:text-zinc-400 border border-gray-300 dark:border-zinc-800 font-mono uppercase tracking-wider">
                    Servidor Conectado • Sin Medios Sincronizados
                  </span>
                  <h2 className="text-xl md:text-2xl font-black text-gray-900 dark:text-white tracking-wide leading-snug">
                    Servidor conectado. No hay medios sincronizados en el catálogo.
                  </h2>
                  <p className="text-xs md:text-sm text-gray-600 dark:text-zinc-400 leading-relaxed">
                    El enlace con la IP del servidor de medios está disponible. Pulsa el botón para descargar todo el catálogo existente o accede a la configuración si necesitas ingresar el token de acceso.
                  </p>
                </div>

                <div className="flex flex-wrap items-center justify-center gap-3 pt-2">
                  <button
                    onClick={handleSyncMedia}
                    disabled={isSyncing}
                    className="flex items-center gap-2 bg-[#E50914] hover:bg-[#F40612] text-white px-5 py-2.5 rounded-lg text-xs font-bold transition-all shadow-lg active:scale-95 disabled:opacity-60 cursor-pointer"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin' : ''}`} />
                    {isSyncing ? 'Conectando...' : 'Comprobar / Sincronizar Almacenamiento'}
                  </button>
                  {user?.role === 'admin' && (
                    <button
                      onClick={() => onNavigateToAdmin ? onNavigateToAdmin('network') : (window.location.href = '/admin/network')}
                      className="flex items-center gap-2 bg-white dark:bg-zinc-800 hover:bg-gray-100 dark:hover:bg-zinc-700 text-gray-700 dark:text-zinc-200 hover:text-gray-900 dark:hover:text-white px-4 py-2.5 rounded-lg text-xs font-semibold transition-colors border border-gray-200 dark:border-zinc-700 shadow-sm cursor-pointer"
                    >
                      <Settings className="w-4 h-4" />
                      Configurar IP / Token de Red
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* Barra de Filtros Rápidos por Plataforma OTT y Género */}
            {hasContent && (
              <div
                className={`px-4 md:px-12 z-20 relative ${
                  currentTab === 'home'
                    ? '-mt-12 md:-mt-16 mb-4 md:mb-6'
                    : 'pt-24 mb-6'
                }`}
              >
                {/* Encabezado descriptivo en vistas específicas de Películas y Series */}
                {currentTab !== 'home' && (
                  <div className="mb-4">
                    <h1 className="text-xl md:text-3xl font-black text-gray-900 dark:text-white tracking-wide">
                      {currentTab === 'movies'
                        ? 'Catálogo de Películas'
                        : currentTab === 'series'
                        ? 'Catálogo de Series'
                        : 'Aclamados y Destacados'}
                    </h1>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                      Filtra por plataforma OTT sincronizada (Netflix, Disney+, HBO Max, Prime Video) o catálogo local FlixHN.
                    </p>
                  </div>
                )}

                <CatalogFilterBar
                  selectedPlatform={selectedPlatform}
                  onSelectPlatform={setSelectedPlatform}
                  selectedGenre={selectedGenre}
                  onSelectGenre={setSelectedGenre}
                  availableGenres={availableGenres}
                  totalCount={allFilteredItems.length}
                  viewMode={viewMode}
                  onToggleViewMode={setViewMode}
                  onResetFilters={handleResetFilters}
                  currentTab={currentTab}
                />
              </div>
            )}

            {/* Contenedor de Medios: Carruseles Horizontales o Cuadrícula Filtrada */}
            {hasContent && (
              <div className="space-y-4 md:space-y-8">
                {loading && rows.length === 0 ? (
                  <div className="h-48 flex items-center justify-center">
                    <div className="w-8 h-8 border-3 border-red-600 border-t-transparent rounded-full animate-spin"></div>
                  </div>
                ) : filteredRows.length === 0 ? (
                  /* Estado vacío al filtrar sin resultados */
                  <div className="py-16 px-4 text-center space-y-4 animate-fadeIn">
                    <div className="w-16 h-16 mx-auto rounded-2xl bg-zinc-200 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                      <Film className="w-8 h-8 stroke-[1.5]" />
                    </div>
                    <div className="space-y-1">
                      <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                        No se encontraron títulos con los filtros seleccionados
                      </h3>
                      <p className="text-xs text-zinc-500 max-w-md mx-auto">
                        No hay contenido disponible para la combinación de plataforma y género actual.
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={handleResetFilters}
                      className="inline-flex items-center gap-1.5 px-4 py-2 bg-[#E50914] hover:bg-[#F40612] text-white text-xs font-bold rounded-lg transition-all shadow-lg active:scale-95 cursor-pointer"
                    >
                      Restablecer todos los filtros
                    </button>
                  </div>
                ) : viewMode === 'grid' && (selectedPlatform !== 'all' || selectedGenre !== 'all') ? (
                  /* Vista en Cuadrícula cuando el usuario prefiere grid o filtra */
                  <div className="px-4 md:px-12 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-5 animate-fadeIn">
                    {allFilteredItems.map((item) => (
                      <MovieCard
                        key={item?.id}
                        item={item}
                        onPlay={(t, ep) => onPlay?.(t, ep)}
                        onOpenModal={(t) => setModalTitleId(t?.id)}
                      />
                    ))}
                  </div>
                ) : (
                  /* Vista en Carruseles Horizontales */
                  (filteredRows || []).map((row) => (
                    <MovieRow
                      key={row?.id}
                      title={row?.title}
                      items={row?.items || []}
                      isProgressRow={row?.is_progress_row}
                      onPlay={(t, ep) => onPlay?.(t, ep)}
                      onOpenModal={(t) => setModalTitleId(t?.id)}
                      onRemoveProgress={handleRemoveFromContinueWatching}
                    />
                  ))
                )}
              </div>
            )}
          </>
        )}
      </main>

      {modalTitleId && (
        <DetailModal
          titleId={modalTitleId}
          onClose={() => setModalTitleId(null)}
          onPlay={(t, ep, time) => {
            setModalTitleId(null);
            onPlay?.(t, ep, time);
          }}
        />
      )}
    </div>
  );
};

export default Browse;
