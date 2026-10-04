import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from './context/AuthContext';
import { useLanguage } from './context/LanguageContext';
import Login from './components/Login';
import ProfileSelector from './components/ProfileSelector';
import Navbar from './components/Navbar.jsx';
import Billboard from './components/Billboard';
import MovieRow from './components/MovieRow';
import MovieCard from './components/MovieCard';
import DetailModal from './components/DetailModal';
import VideoPlayer from './components/VideoPlayer';
import AdminLayout from './pages/admin/AdminLayout';
import { HardDrive, RefreshCw, Settings } from 'lucide-react';
import api from './api/axios';

function App() {
  const { user, token, activeProfile, loading } = useAuth();
  const { t } = useLanguage();

  const [currentTab, setCurrentTab] = useState(() => {
    if (typeof window !== 'undefined' && window.location.pathname.startsWith('/admin')) {
      return 'admin';
    }
    return 'home';
  });
  const [billboardTitle, setBillboardTitle] = useState(null);
  const [rows, setRows] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [isSyncing, setIsSyncing] = useState(false);

  // Estados de búsqueda reactiva en tiempo real (Estilo Netflix)
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState([]);

  // Estados de modales y reproducción
  const [modalTitleId, setModalTitleId] = useState(null);
  const [activeVideo, setActiveVideo] = useState(null); // { title, episode, initialTime }

  // Carga del catálogo desde la API de Laravel
  const loadCatalog = useCallback(() => {
    if (!token || !activeProfile) return;

    setCatalogLoading(true);
    api.get(`/titles?profile_id=${activeProfile.id}`)
      .then((res) => {
        if (res.data) {
          setBillboardTitle(res.data.billboard || null);
          setRows(res.data.rows || []);
        } else {
          setBillboardTitle(null);
          setRows([]);
        }
      })
      .catch((err) => {
        console.error('Error cargando catálogo FlixHN:', err);
        setBillboardTitle(null);
        setRows([]);
      })
      .finally(() => {
        setCatalogLoading(false);
      });
  }, [token, activeProfile?.id]);

  const handleSyncMedia = async () => {
    setIsSyncing(true);
    try {
      if (user?.role === 'admin') {
        await api.post('/admin/media/sync');
      }
      await loadCatalog();
    } catch (e) {
      console.warn('Error sincronizando medios:', e);
      await loadCatalog();
    } finally {
      setIsSyncing(false);
    }
  };

  useEffect(() => {
    loadCatalog();
  }, [loadCatalog]);

  // Sincronización de URL /admin, /browse, /login y navegación del navegador (Blindaje de Sesión)
  useEffect(() => {
    const currentPath = window.location.pathname;
    if (currentPath.startsWith('/admin') && user?.role === 'admin') {
      setCurrentTab((prev) => (prev !== 'admin' ? 'admin' : prev));
    } else if (currentPath.startsWith('/browse') || currentPath === '/' || currentPath === '') {
      setCurrentTab((prev) => (prev !== 'home' ? 'home' : prev));
    } else if (currentPath === '/login' && token && user) {
      window.history.replaceState({}, '', '/browse');
      setCurrentTab('home');
    }

    const handlePopState = () => {
      const path = window.location.pathname;
      if (path.startsWith('/admin') && user?.role === 'admin') {
        setCurrentTab((prev) => (prev !== 'admin' ? 'admin' : prev));
      } else if (path === '/' || path === '' || path.startsWith('/browse')) {
        setCurrentTab((prev) => (prev !== 'home' ? 'home' : prev));
      }
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [token, user?.role]);

  // Manejo de Reproducción con soporte para reanudación en el segundo exacto
  const handlePlay = useCallback((title, episode = null, resumeTime = null) => {
    const startSec = resumeTime !== null
      ? resumeTime
      : (title?.progress_seconds || episode?.progress_seconds || 0);

    setActiveVideo({
      title,
      episode,
      initialTime: startSec,
    });
  }, []);

  // Manejo de Apertura de Modal Informativo
  const handleOpenModal = useCallback((title) => {
    setModalTitleId(title.id);
  }, []);

  // Callbacks memoizados para el Navbar y búsqueda reactiva
  const handleSearchResults = useCallback((results, query) => {
    setSearchResults(results);
    setSearchQuery(query);
  }, []);

  const handleSearchQueryChange = useCallback((query) => {
    setSearchQuery(query);
  }, []);

  const handleTabChange = useCallback((tab) => {
    setSearchQuery('');
    setSearchResults([]);
    setCurrentTab(tab);
  }, []);

  // Eliminación de título de la fila "Continuar Viendo"
  const handleRemoveFromContinueWatching = async (titleId, episodeId = null) => {
    // Actualización optimista inmediata en la UI
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
      loadCatalog();
    }
  };

  // 1. Estado de Carga Inicial
  if (loading) {
    return (
      <div className="min-h-screen bg-[#141414] flex flex-col items-center justify-center space-y-4">
        <span className="font-display text-5xl md:text-6xl text-[#E50914] font-black tracking-widest animate-pulse">
          FLIXHN
        </span>
        <div className="w-8 h-8 border-3 border-red-600 border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  // 2. Si no hay sesión autenticada -> Vista Login
  if (!token || !user) {
    return <Login />;
  }

  // 3. Si el usuario no ha seleccionado un perfil ("¿Quién está viendo?")
  if (!activeProfile) {
    return <ProfileSelector />;
  }

  // 4. Si el reproductor de video está activo -> Pantalla Completa HLS
  if (activeVideo) {
    return (
      <VideoPlayer
        title={activeVideo.title}
        episode={activeVideo.episode}
        initialTime={activeVideo.initialTime || 0}
        onBack={() => {
          setActiveVideo(null);
          loadCatalog(); // Refrescar la fila de "Continuar Viendo"
        }}
      />
    );
  }

  // 5. Si está activa la pestaña de Administración (Consola ISP)
  if (currentTab === 'admin' && user?.role === 'admin') {
    return (
      <AdminLayout
        onBackToBrowse={() => {
          setCurrentTab('home');
          window.history.pushState({}, '', '/browse');
          loadCatalog();
        }}
      />
    );
  }

  // Filtrado de filas según la pestaña seleccionada con protección de datos nulos
  let filteredRows = rows || [];
  if (currentTab === 'movies') {
    filteredRows = (rows || []).filter((r) => r?.id === 'movies_row' || (r?.id !== 'series_row' && (r?.items || []).some((i) => (i?.type === 'movie' || i?.title?.type === 'movie'))));
  } else if (currentTab === 'series') {
    filteredRows = (rows || []).filter((r) => r?.id === 'series_row' || (r?.id !== 'movies_row' && (r?.items || []).some((i) => (i?.type === 'series' || i?.title?.type === 'series'))));
  } else if (currentTab === 'featured') {
    filteredRows = (rows || []).filter((r) => r?.id === 'featured_row' || r?.id === 'continue_watching');
  }

  const hasContent = Boolean(billboardTitle) || (rows || []).some((r) => r?.items && r.items.length > 0);
  const isSearchActive = searchQuery.trim().length > 0;

  return (
    <div className="min-h-screen bg-gray-100 dark:bg-[#141414] text-gray-900 dark:text-white flex flex-col justify-between selection:bg-[#E50914] selection:text-white transition-colors duration-200">
      {/* Barra de Navegación Dinámica */}
      <Navbar
        currentTab={currentTab}
        setCurrentTab={handleTabChange}
        onPlay={handlePlay}
        onOpenModal={handleOpenModal}
        onSearchResults={handleSearchResults}
        onSearchQueryChange={handleSearchQueryChange}
      />

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
                    onPlay={(t, ep) => handlePlay(t, ep)}
                    onOpenModal={(t) => handleOpenModal(t)}
                  />
                ))}
              </div>
            )}
          </section>
        ) : (
          /* VISTA 2: CATÁLOGO REGULAR COMPLETO CON BILLBOARD Y TODAS LAS FILAS */
          <>
            {/* Billboard Hero Banner */}
            {billboardTitle && currentTab === 'home' && (
              <Billboard
                title={billboardTitle}
                onPlay={(t) => handlePlay(t)}
                onOpenModal={(t) => handleOpenModal(t)}
              />
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
                      Descargando categorías, títulos, pósters y flujos de transmisión desde la IP configurada.
                    </p>
                  </div>
                  <span className="text-[11px] font-mono uppercase tracking-widest text-[#E50914] font-bold animate-pulse">
                    FlixHN DirectPlay Core
                  </span>
                </div>
              </div>
            )}

            {/* Estado Vacío Limpio si no hay contenido en el almacenamiento local del ISP */}
            {!catalogLoading && !hasContent && (
              <div className="min-h-[65vh] flex flex-col items-center justify-center text-center px-4 py-20 space-y-5 animate-fadeIn">
                <div className="w-20 h-20 rounded-2xl bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 flex items-center justify-center text-[#E50914] shadow-md dark:shadow-2xl relative">
                  <HardDrive className="w-10 h-10 stroke-[1.5]" />
                  <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-amber-500 rounded-full border-2 border-white dark:border-[#141414] animate-pulse" />
                </div>

                <div className="space-y-2 max-w-lg">
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-gray-200 dark:bg-zinc-900 text-gray-700 dark:text-zinc-400 border border-gray-300 dark:border-zinc-800 font-mono uppercase tracking-wider">
                    Servidor de Medios Conectado
                  </span>
                  <h2 className="text-2xl md:text-3xl font-black text-gray-900 dark:text-white tracking-wide">
                    No hay contenido disponible en el catálogo aún.
                  </h2>
                  <p className="text-xs md:text-sm text-gray-600 dark:text-zinc-400 leading-relaxed">
                    El enlace con la IP del servidor de medios está configurado. Pulsa el botón para descargar todo el catálogo existente o accede a la configuración si necesitas ingresar el token de acceso.
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
                      onClick={() => {
                        setCurrentTab('admin');
                        window.history.pushState({}, '', '/admin/network');
                      }}
                      className="flex items-center gap-2 bg-white dark:bg-zinc-800 hover:bg-gray-100 dark:hover:bg-zinc-700 text-gray-700 dark:text-zinc-200 hover:text-gray-900 dark:hover:text-white px-4 py-2.5 rounded-lg text-xs font-semibold transition-colors border border-gray-200 dark:border-zinc-700 shadow-sm cursor-pointer"
                    >
                      <Settings className="w-4 h-4" />
                      Configurar IP / Token de Red
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* Carruseles Horizontales de Contenido Completo */}
            {hasContent && (
              <div className={`space-y-4 md:space-y-8 ${currentTab === 'home' ? '-mt-16 md:-mt-24 z-20 relative' : 'pt-24'}`}>
                {catalogLoading && rows.length === 0 ? (
                  <div className="h-48 flex items-center justify-center">
                    <div className="w-8 h-8 border-3 border-red-600 border-t-transparent rounded-full animate-spin"></div>
                  </div>
                ) : (
                  (filteredRows || []).map((row) => (
                    <MovieRow
                      key={row?.id}
                      title={row?.title}
                      items={row?.items || []}
                      isProgressRow={row?.is_progress_row}
                      onPlay={(t, ep) => handlePlay(t, ep)}
                      onOpenModal={(t) => handleOpenModal(t)}
                      onRemoveProgress={handleRemoveFromContinueWatching}
                    />
                  ))
                )}
              </div>
            )}
          </>
        )}
      </main>

      {/* Modal Expandible de Detalle */}
      {modalTitleId && (
        <DetailModal
          titleId={modalTitleId}
          onClose={() => setModalTitleId(null)}
          onPlay={(t, ep, time) => {
            setModalTitleId(null);
            handlePlay(t, ep, time);
          }}
        />
      )}

      {/* Pie de Página FlixHN */}
      <footer className="px-6 md:px-14 py-8 border-t border-gray-200 dark:border-neutral-900 text-gray-500 dark:text-gray-500 text-xs space-y-4 transition-colors">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="space-y-1">
            <p className="font-semibold text-gray-700 dark:text-gray-400">FlixHN Streaming On-Net</p>
            <p>Servicio exclusivo de alta disponibilidad para la red de fibra óptica del ISP.</p>
          </div>
          <div className="flex items-center gap-6 text-gray-500 dark:text-gray-400">
            <span>Términos de Servicio ISP</span>
            <span>Centro de Ayuda y Soporte NOC</span>
            <span>Latencia: &lt; 2ms On-Net</span>
          </div>
        </div>
        <p className="text-[11px] text-gray-400 dark:text-gray-600 text-center pt-4">
          © {new Date().getFullYear()} FlixHN. Todos los derechos reservados.
        </p>
      </footer>
    </div>
  );
}

export default App;
