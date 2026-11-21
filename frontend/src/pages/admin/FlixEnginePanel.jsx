import React, { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import {
  Search,
  Sparkles,
  Film,
  Tv,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  ExternalLink,
  Play,
  DownloadCloud,
  Trash2,
  Clock,
  Star,
  Subtitles,
  Server,
  Layers,
  X,
  Plus,
  History,
  Youtube,
  Compass,
} from 'lucide-react';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';

const MOVIE_SECTIONS = [
  { id: 'trending', label: 'Tendencias' },
  { id: 'popular', label: 'Populares' },
  { id: 'top_rated', label: 'Mejor Valoradas' },
  { id: 'now_playing', label: 'En Cartelera' },
  { id: 'upcoming', label: 'Próximos Estrenos' },
  { id: 'discover', label: 'Descubrir (Filtros)' },
];

const SHOW_SECTIONS = [
  { id: 'trending', label: 'Tendencias' },
  { id: 'popular', label: 'Populares' },
  { id: 'top_rated', label: 'Mejor Valoradas' },
  { id: 'airing_today', label: 'Emitiéndose Hoy' },
  { id: 'on_the_air', label: 'Al Aire' },
  { id: 'discover', label: 'Descubrir (Filtros)' },
];

export default function FlixEnginePanel({ showToast }) {
  const [subTab, setSubTab] = useState('catalog'); // catalog | providers | subtitles | maintenance
  const [status, setStatus] = useState(null);
  const [statusLoading, setStatusLoading] = useState(true);

  // Explorador TMDB
  const [mediaType, setMediaType] = useState('movie'); // movie | series
  const [section, setSection] = useState('trending');
  const [genres, setGenres] = useState([]);
  const [selectedGenre, setSelectedGenre] = useState('');
  const [selectedYear, setSelectedYear] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [activeQuery, setActiveQuery] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [items, setItems] = useState([]);
  const [loadingItems, setLoadingItems] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  // Historial de búsquedas (port de lib/storage.py)
  const [historyItems, setHistoryItems] = useState([]);

  // Modal de detalles + sincronización + proveedores 2 fases
  const [selectedItem, setSelectedItem] = useState(null);
  const [detailData, setDetailData] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [syncingId, setSyncingId] = useState(null);

  // Resultados de proveedores 2 fases (port de lib/providers.py)
  const [providerQuery, setProviderQuery] = useState('');
  const [providerCandidates, setProviderCandidates] = useState([]);
  const [providerReplay, setProviderReplay] = useState(null);
  const [providerLoading, setProviderLoading] = useState(false);
  const [resolvedPayload, setResolvedPayload] = useState(null);

  // Buscador OpenSubtitles (port de lib/subtitles.py)
  const [subQuery, setSubQuery] = useState('');
  const [subLangs, setSubLangs] = useState('es,en');
  const [subImdb, setSubImdb] = useState('');
  const [subSeason, setSubSeason] = useState('');
  const [subEpisode, setSubEpisode] = useState('');
  const [subResults, setSubResults] = useState([]);
  const [subLoading, setSubLoading] = useState(false);
  const [downloadingSubId, setDownloadingSubId] = useState(null);

  // Mantenimiento
  const [updatingShows, setUpdatingShows] = useState(false);
  const [clearingCache, setClearingCache] = useState(false);

  const notify = useCallback(
    (type, msg, title = 'Flix Engine') => {
      if (typeof showToast === 'function') {
        showToast(type, msg, title);
      }
    },
    [showToast]
  );

  const loadStatus = useCallback(async () => {
    setStatusLoading(true);
    try {
      const res = await api.get('/admin/flix/status');
      if (res.data?.success) {
        setStatus(res.data);
      }
    } catch (err) {
      console.warn('Error cargando estado FlixEngine:', err);
    } finally {
      setStatusLoading(false);
    }
  }, []);

  const loadHistory = useCallback(async () => {
    try {
      const res = await api.get('/admin/flix/history', { params: { type: 'all', page: 1 } });
      if (res.data?.success) {
        setHistoryItems(res.data.items || []);
      }
    } catch {
      // Silencioso si no hay historial aún
    }
  }, []);

  const loadGenres = useCallback(async (type) => {
    try {
      const res = await api.get(`/admin/flix/genres/${type === 'series' ? 'tv' : 'movie'}`);
      if (res.data?.success) {
        setGenres(res.data.genres || []);
      }
    } catch {
      setGenres([]);
    }
  }, []);

  const loadCatalog = useCallback(
    async (targetPage = 1, overrideQuery = null) => {
      setLoadingItems(true);
      setErrorMsg('');
      const q = overrideQuery !== null ? overrideQuery : activeQuery;

      try {
        let res;
        if (q.trim() !== '') {
          res = await api.get('/admin/flix/search', {
            params: {
              q: q.trim(),
              type: mediaType === 'series' ? 'tv' : 'movie',
              page: targetPage,
            },
          });
          loadHistory();
        } else {
          res = await api.get('/admin/flix/discover', {
            params: {
              type: mediaType,
              section,
              page: targetPage,
              with_genres: section === 'discover' && selectedGenre ? selectedGenre : undefined,
              year: section === 'discover' && selectedYear ? selectedYear : undefined,
            },
          });
        }

        if (res.data?.success) {
          setItems(res.data.results || []);
          setPage(res.data.page || targetPage);
          setTotalPages(Math.min(res.data.total_pages || 1, 500));
        } else {
          setItems([]);
          setErrorMsg(res.data?.message || 'No se pudieron obtener resultados de TMDB.');
        }
      } catch (err) {
        setItems([]);
        setErrorMsg(err?.response?.data?.message || 'Error consultando el motor TMDB.');
      } finally {
        setLoadingItems(false);
      }
    },
    [activeQuery, mediaType, section, selectedGenre, selectedYear, loadHistory]
  );

  useEffect(() => {
    loadStatus();
    loadHistory();
  }, [loadStatus, loadHistory]);

  useEffect(() => {
    loadGenres(mediaType);
  }, [mediaType, loadGenres]);

  useEffect(() => {
    if (subTab === 'catalog' && status?.tmdb?.configured) {
      loadCatalog(1);
    }
  }, [subTab, mediaType, section, selectedGenre, selectedYear, activeQuery, status?.tmdb?.configured, loadCatalog]);

  const handleSearchSubmit = (e) => {
    e.preventDefault();
    setActiveQuery(searchInput.trim());
    setPage(1);
  };

  const handleOpenDetails = async (item) => {
    setSelectedItem(item);
    setDetailData(null);
    setDetailLoading(true);
    setProviderCandidates([]);
    setResolvedPayload(null);

    try {
      const res = await api.get(`/admin/flix/details/${item.type}/${item.tmdb_id}`);
      if (res.data?.success) {
        setDetailData(res.data);
      } else {
        notify('error', res.data?.message || 'No se pudieron cargar los detalles.');
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error cargando detalles TMDB.');
    } finally {
      setDetailLoading(false);
    }
  };

  const handleSyncToLibrary = async (item) => {
    setSyncingId(item.tmdb_id);
    try {
      const res = await api.post('/admin/flix/library/sync', {
        type: item.type,
        tmdb_id: item.tmdb_id,
        include_specials: false,
        include_unaired: false,
      });
      if (res.data?.success) {
        notify('success', res.data.message || 'Sincronizado en biblioteca FlixHN.', 'Biblioteca Actualizada');
        setItems((prev) =>
          prev.map((it) =>
            it.tmdb_id === item.tmdb_id && it.type === item.type
              ? { ...it, in_library: true, local_title_id: res.data?.data?.title?.id }
              : it
          )
        );
        if (detailData?.details?.tmdb_id === item.tmdb_id) {
          setDetailData((prev) => ({ ...prev, in_library: true, local_title_id: res.data?.data?.title?.id }));
        }
        notifyCatalogUpdated({ action: 'flix_library_sync', tmdb_id: item.tmdb_id });
        loadStatus();
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'No se pudo sincronizar el título.');
    } finally {
      setSyncingId(null);
    }
  };

  // Fase 1: Buscar candidatos en proveedores acoplados
  const handleProviderSearch = async (payload) => {
    setProviderLoading(true);
    setResolvedPayload(null);
    try {
      const res = await api.post('/admin/flix/providers/search', payload);
      if (res.data?.success) {
        setProviderCandidates(res.data.results || []);
        setProviderReplay(res.data.last_played || null);
        if ((res.data.results || []).length === 0) {
          notify('info', 'Ningún proveedor activo devolvió coincidencias para este título.');
        }
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error consultando proveedores.');
    } finally {
      setProviderLoading(false);
    }
  };

  // Fase 2: Resolución perezosa (Provider.resolve)
  const handleResolveCandidate = async (candidate, replayKey = null) => {
    if (candidate.url && !candidate.needs_resolve) {
      setResolvedPayload({
        success: true,
        provider: candidate.provider,
        playback_type: candidate.playback_type,
        stream_url: candidate.url,
        direct_playback: !candidate.requires_external_app,
        message: 'URL directa lista sin necesidad de fase resolve.',
      });
      return;
    }

    setProviderLoading(true);
    try {
      const res = await api.post('/admin/flix/providers/resolve', {
        provider: candidate.provider,
        provider_data: candidate.provider_data,
        replay_key: replayKey || undefined,
      });
      setResolvedPayload(res.data);
      if (res.data?.success) {
        notify('success', `Enlace resuelto vía ${candidate.provider} (${res.data.playback_type || 'OK'}).`, 'Fase 2 Resolve');
      } else {
        notify('error', res.data?.message || 'El proveedor no pudo resolver el enlace.');
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error ejecutando resolve() en el proveedor.');
    } finally {
      setProviderLoading(false);
    }
  };

  const handleSubtitleSearch = async (e) => {
    if (e) e.preventDefault();
    setSubLoading(true);
    try {
      const res = await api.post('/admin/flix/subtitles/search', {
        query: subQuery.trim() || undefined,
        imdb_id: subImdb.trim() || undefined,
        season_number: subSeason ? Number(subSeason) : undefined,
        episode_number: subEpisode ? Number(subEpisode) : undefined,
        languages: subLangs,
      });
      if (res.data?.success) {
        setSubResults(res.data.results || []);
        if ((res.data.results || []).length === 0) {
          notify('info', 'No se encontraron subtítulos en OpenSubtitles para esos criterios.');
        }
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error consultando OpenSubtitles.');
    } finally {
      setSubLoading(false);
    }
  };

  const handleSubtitleDownload = async (fileId, fileName) => {
    setDownloadingSubId(fileId);
    try {
      const res = await api.post('/admin/flix/subtitles/download', {
        file_id: fileId,
        file_name: fileName,
      });
      if (res.data?.success) {
        notify('success', `Subtítulo guardado en: ${res.data.saved_path}`, 'OpenSubtitles');
      }
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error descargando subtítulo.');
    } finally {
      setDownloadingSubId(null);
    }
  };

  const handleUpdateTrackedShows = async () => {
    setUpdatingShows(true);
    try {
      const res = await api.post('/admin/flix/library/update-shows');
      notify('success', res.data?.message || 'Series actualizadas.', 'Auto-Update Series');
      loadStatus();
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error actualizando series.');
    } finally {
      setUpdatingShows(false);
    }
  };

  const handleClearCache = async () => {
    setClearingCache(true);
    try {
      const res = await api.post('/admin/flix/cache/clear');
      notify('info', res.data?.message || 'Caché purgada.', 'Caché Flix');
    } catch (err) {
      notify('error', err?.response?.data?.message || 'Error limpiando caché.');
    } finally {
      setClearingCache(false);
    }
  };

  const handleDeleteHistory = async (id) => {
    try {
      await api.delete(`/admin/flix/history/${id}`);
      setHistoryItems((prev) => prev.filter((h) => h.id !== id));
    } catch {}
  };

  const handleClearHistory = async () => {
    try {
      await api.post('/admin/flix/history/clear');
      setHistoryItems([]);
      notify('info', 'Historial de búsquedas limpiado.');
    } catch {}
  };

  const sectionsList = mediaType === 'series' ? SHOW_SECTIONS : MOVIE_SECTIONS;

  return (
    <div className="bg-[#111827]/95 border border-zinc-800 rounded-2xl p-6 shadow-2xl space-y-6">
      {/* Cabecera Motor Flix */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-5 border-b border-zinc-800">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
              <Sparkles className="w-3 h-3" />
              plugin.video.flix Core Port
            </span>
            {status?.tmdb?.configured ? (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-950/80 text-emerald-300 border border-emerald-500/30">
                <CheckCircle2 className="w-3 h-3" /> TMDB Activo ({status.tmdb.language})
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-950/80 text-amber-300 border border-amber-500/30">
                <AlertCircle className="w-3 h-3" /> TMDB API Key pendiente
              </span>
            )}
          </div>
          <h2 className="text-xl font-black text-white">Motor Flix — Descubrimiento TMDB, Proveedores 2-Fases & Subtítulos</h2>
          <p className="text-xs text-zinc-400 mt-1">
            Arquitectura desacoplada de <code className="text-zinc-300">plugin.video.flix</code> integrada directamente sobre el catálogo Eloquent, adaptadores OTT y tareas programadas de FlixHN.
          </p>
        </div>

        {/* Sub-pestañas del Motor Flix */}
        <div className="flex flex-wrap gap-1.5 bg-zinc-950/80 p-1.5 rounded-xl border border-zinc-800">
          {[
            { id: 'catalog', label: 'Catálogo TMDB', icon: Compass },
            { id: 'providers', label: `Proveedores (${status?.providers?.length || 0})`, icon: Layers },
            { id: 'subtitles', label: 'OpenSubtitles v1', icon: Subtitles },
            { id: 'maintenance', label: 'Sync & Caché', icon: Server },
          ].map((tab) => {
            const Icon = tab.icon;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setSubTab(tab.id)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                  subTab === tab.id
                    ? 'bg-[#E50914] text-white shadow'
                    : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
                }`}
              >
                <Icon className="w-3.5 h-3.5" />
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* TAB 1: CATÁLOGO Y DESCUBRIMIENTO TMDB */}
      {subTab === 'catalog' && (
        <div className="space-y-5">
          {!statusLoading && !status?.tmdb?.configured && (
            <div className="p-4 rounded-xl bg-amber-950/40 border border-amber-500/30 text-xs text-amber-200 flex items-start gap-3">
              <AlertCircle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
              <div>
                <p className="font-bold text-amber-100">Configura tu API Key de TMDB para activar el explorador</p>
                <p className="mt-1 text-amber-200/80">
                  Ve a <strong>Avanzado → Plugins → TheMovieDB (TMDb)</strong> e ingresa tu clave API oficial de TMDB v3, o define <code className="bg-black/40 px-1.5 py-0.5 rounded">TMDB_API_KEY</code> en <code className="bg-black/40 px-1.5 py-0.5 rounded">backend/.env</code>.
                </p>
              </div>
            </div>
          )}

          {/* Barra de búsqueda + controles */}
          <div className="flex flex-col lg:flex-row gap-3">
            <div className="flex bg-zinc-950 border border-zinc-800 rounded-xl p-1 shrink-0">
              <button
                type="button"
                onClick={() => {
                  setMediaType('movie');
                  setSelectedGenre('');
                  setSection('trending');
                }}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer ${
                  mediaType === 'movie' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'
                }`}
              >
                <Film className="w-3.5 h-3.5" /> Películas
              </button>
              <button
                type="button"
                onClick={() => {
                  setMediaType('series');
                  setSelectedGenre('');
                  setSection('trending');
                }}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer ${
                  mediaType === 'series' ? 'bg-red-600 text-white' : 'text-zinc-400 hover:text-white'
                }`}
              >
                <Tv className="w-3.5 h-3.5" /> Series TV
              </button>
            </div>

            <form onSubmit={handleSearchSubmit} className="flex-1 flex gap-2">
              <div className="relative flex-1">
                <Search className="w-4 h-4 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  placeholder={`Buscar ${mediaType === 'series' ? 'series' : 'películas'} en TMDB...`}
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-xl pl-9 pr-9 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500/60"
                />
                {searchInput && (
                  <button
                    type="button"
                    onClick={() => {
                      setSearchInput('');
                      setActiveQuery('');
                    }}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white cursor-pointer"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
              <button
                type="submit"
                className="px-4 py-2 bg-[#E50914] hover:bg-red-700 text-white text-xs font-bold rounded-xl cursor-pointer"
              >
                Buscar
              </button>
            </form>
          </div>

          {/* Secciones TMDB y Filtros Discover */}
          {!activeQuery && (
            <div className="flex flex-wrap items-center gap-2">
              {sectionsList.map((sec) => (
                <button
                  key={sec.id}
                  type="button"
                  onClick={() => setSection(sec.id)}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                    section === sec.id
                      ? 'bg-red-600/20 border-red-500/50 text-red-300'
                      : 'bg-zinc-950/70 border-zinc-800 text-zinc-400 hover:text-white'
                  }`}
                >
                  {sec.label}
                </button>
              ))}

              {section === 'discover' && (
                <div className="flex flex-wrap items-center gap-2 ml-auto">
                  <select
                    value={selectedGenre}
                    onChange={(e) => setSelectedGenre(e.target.value)}
                    className="bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-200"
                  >
                    <option value="">Todos los géneros</option>
                    {genres.map((g) => (
                      <option key={g.id} value={g.id}>
                        {g.name}
                      </option>
                    ))}
                  </select>
                  <input
                    type="number"
                    min="1900"
                    max={new Date().getFullYear() + 1}
                    value={selectedYear}
                    onChange={(e) => setSelectedYear(e.target.value)}
                    placeholder="Año (ej. 2024)"
                    className="w-32 bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-200"
                  />
                </div>
              )}
            </div>
          )}

          {/* Historial de Búsquedas (SearchHistory) */}
          {historyItems.length > 0 && (
            <div className="flex flex-wrap items-center gap-2 bg-zinc-950/60 border border-zinc-800/80 rounded-xl px-3 py-2">
              <span className="text-[11px] text-zinc-400 font-semibold flex items-center gap-1 mr-1">
                <History className="w-3.5 h-3.5 text-zinc-500" /> Recientes:
              </span>
              {historyItems.slice(0, 8).map((h) => (
                <span
                  key={h.id}
                  className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-zinc-900 border border-zinc-800 text-[11px] text-zinc-300"
                >
                  <button
                    type="button"
                    onClick={() => {
                      setSearchInput(h.query);
                      setActiveQuery(h.query);
                    }}
                    className="hover:text-white cursor-pointer"
                  >
                    {h.query}
                  </button>
                  <button
                    type="button"
                    onClick={() => handleDeleteHistory(h.id)}
                    className="text-zinc-500 hover:text-red-400 cursor-pointer"
                    title="Eliminar del historial"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </span>
              ))}
              <button
                type="button"
                onClick={handleClearHistory}
                className="ml-auto text-[11px] text-zinc-500 hover:text-red-400 cursor-pointer"
              >
                Limpiar
              </button>
            </div>
          )}

          {errorMsg && (
            <div className="p-3 rounded-xl bg-red-950/50 border border-red-500/30 text-xs text-red-200">
              {errorMsg}
            </div>
          )}

          {/* Grid de resultados */}
          {loadingItems ? (
            <div className="flex items-center justify-center py-12 gap-2 text-xs text-zinc-400">
              <RefreshCw className="w-4 h-4 animate-spin text-red-500" />
              <span>Consultando TMDB (caché habilitada)...</span>
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
              {items.map((item) => (
                <div
                  key={`${item.type}-${item.tmdb_id}`}
                  className="bg-zinc-950/80 border border-zinc-800 hover:border-zinc-700 rounded-xl overflow-hidden flex flex-col group transition-all"
                >
                  <div
                    onClick={() => handleOpenDetails(item)}
                    className="relative aspect-[2/3] bg-zinc-900 overflow-hidden cursor-pointer"
                  >
                    {item.poster_url ? (
                      <img
                        src={item.poster_url}
                        alt={item.title}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                        loading="lazy"
                      />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center text-zinc-600 text-xs p-3 text-center">
                        {item.title}
                      </div>
                    )}
                    <div className="absolute top-2 left-2 flex flex-col gap-1">
                      {item.rating > 0 && (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/80 text-amber-400 text-[10px] font-bold">
                          <Star className="w-2.5 h-2.5 fill-amber-400" /> {item.rating}
                        </span>
                      )}
                      {item.in_library && (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-600/90 text-white text-[10px] font-bold">
                          <CheckCircle2 className="w-2.5 h-2.5" /> En Biblioteca
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="p-3 flex-1 flex flex-col justify-between gap-2">
                    <div>
                      <h3 className="text-xs font-bold text-white line-clamp-1" title={item.title}>
                        {item.title}
                      </h3>
                      <p className="text-[11px] text-zinc-400">
                        {item.release_year || '—'} • {item.type === 'series' ? 'Serie' : 'Película'}
                      </p>
                    </div>
                    <div className="flex gap-1.5">
                      <button
                        type="button"
                        onClick={() => handleOpenDetails(item)}
                        className="flex-1 py-1.5 px-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-[11px] font-semibold text-zinc-200 cursor-pointer"
                      >
                        Detalles
                      </button>
                      <button
                        type="button"
                        disabled={syncingId === item.tmdb_id}
                        onClick={() => handleSyncToLibrary(item)}
                        className="py-1.5 px-2.5 rounded-lg bg-[#E50914] hover:bg-red-700 text-white text-[11px] font-bold cursor-pointer disabled:opacity-50"
                        title="Importar/Actualizar metadatos en FlixHN"
                      >
                        {syncingId === item.tmdb_id ? (
                          <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Plus className="w-3.5 h-3.5" />
                        )}
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Paginación */}
          {items.length > 0 && totalPages > 1 && (
            <div className="flex items-center justify-center gap-3 pt-2">
              <button
                type="button"
                disabled={page <= 1}
                onClick={() => loadCatalog(page - 1)}
                className="px-3 py-1.5 rounded-lg bg-zinc-900 border border-zinc-800 text-xs text-zinc-300 disabled:opacity-40 cursor-pointer"
              >
                Anterior
              </button>
              <span className="text-xs text-zinc-400 font-mono">
                Página {page} / {totalPages}
              </span>
              <button
                type="button"
                disabled={page >= totalPages}
                onClick={() => loadCatalog(page + 1)}
                className="px-3 py-1.5 rounded-lg bg-zinc-900 border border-zinc-800 text-xs text-zinc-300 disabled:opacity-40 cursor-pointer"
              >
                Siguiente
              </button>
            </div>
          )}
        </div>
      )}

      {/* TAB 2: PROVEEDORES (CONTRATO 2 FASES) */}
      {subTab === 'providers' && (
        <div className="space-y-5">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {(status?.providers || []).map((prov) => (
              <div key={prov.id} className="bg-zinc-950/70 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-white">{prov.name}</span>
                  <span
                    className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                      prov.online
                        ? 'bg-emerald-950 text-emerald-300 border border-emerald-500/30'
                        : 'bg-red-950 text-red-300 border border-red-500/30'
                    }`}
                  >
                    {prov.online ? 'ONLINE' : 'OFFLINE'}
                  </span>
                </div>
                <p className="text-[11px] font-mono text-zinc-400">{prov.id}</p>
                <p className="text-[11px] text-zinc-400 line-clamp-2">{prov.message}</p>
                {prov.latency_ms !== null && prov.latency_ms !== undefined && (
                  <p className="text-[10px] text-zinc-500 font-mono">Latencia: {prov.latency_ms} ms</p>
                )}
              </div>
            ))}
          </div>

          <div className="bg-zinc-950/70 border border-zinc-800 rounded-xl p-4 space-y-4">
            <h3 className="text-xs font-bold text-white uppercase tracking-wider">
              Consola de Prueba — Contrato 2 Fases (search → resolve)
            </h3>
            <div className="flex gap-2">
              <input
                type="text"
                value={providerQuery}
                onChange={(e) => setProviderQuery(e.target.value)}
                placeholder="Buscar título en todos los proveedores puenteados (local VOD, HLS, OTT)..."
                className="flex-1 bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white"
              />
              <button
                type="button"
                disabled={providerLoading || !providerQuery.trim()}
                onClick={() => handleProviderSearch({ method: 'search', query: providerQuery.trim() })}
                className="px-4 py-2 bg-[#E50914] hover:bg-red-700 text-white text-xs font-bold rounded-xl cursor-pointer disabled:opacity-50"
              >
                {providerLoading ? 'Consultando...' : 'Fase 1: Buscar'}
              </button>
            </div>

            {providerReplay && (
              <div className="p-3 rounded-lg bg-sky-950/40 border border-sky-500/30 text-xs text-sky-200 flex items-center justify-between">
                <span>
                  <Clock className="w-3.5 h-3.5 inline mr-1" />
                  Último enlace en caché (Replay): <strong>{providerReplay.provider}</strong> ({providerReplay.saved_at})
                </span>
              </div>
            )}

            {providerCandidates.length > 0 && (
              <div className="space-y-2">
                {providerCandidates.map((cand, idx) => (
                  <div
                    key={idx}
                    className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-xl bg-zinc-900/90 border border-zinc-800"
                  >
                    <div className="min-w-0">
                      <p className="text-xs font-bold text-white truncate">{cand.label}</p>
                      <p className="text-[11px] text-zinc-400">
                        {cand.label2} • <code className="text-zinc-500">{cand.provider}</code>
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => handleResolveCandidate(cand, `search:${providerQuery}`)}
                      className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold cursor-pointer"
                    >
                      <Play className="w-3.5 h-3.5" />
                      <span>{cand.needs_resolve ? 'Fase 2: Resolve' : 'Usar URL Directa'}</span>
                    </button>
                  </div>
                ))}
              </div>
            )}

            {resolvedPayload && (
              <div className="p-4 rounded-xl bg-black/50 border border-zinc-800 space-y-2 text-xs">
                <p className="font-bold text-emerald-400">Resultado de Resolución:</p>
                <pre className="text-[11px] text-zinc-300 overflow-x-auto font-mono">
                  {JSON.stringify(resolvedPayload, null, 2)}
                </pre>
              </div>
            )}
          </div>
        </div>
      )}

      {/* TAB 3: OPENSUBTITLES REST V1 */}
      {subTab === 'subtitles' && (
        <div className="space-y-4">
          {!status?.opensubtitles?.configured && (
            <div className="p-4 rounded-xl bg-amber-950/40 border border-amber-500/30 text-xs text-amber-200">
              Configura tu API Key de OpenSubtitles en <strong>Avanzado → Plugins → OpenSubtitles</strong> o mediante <code className="bg-black/40 px-1 rounded">OPENSUBTITLES_API_KEY</code> en <code className="bg-black/40 px-1 rounded">backend/.env</code>.
            </div>
          )}

          <form onSubmit={handleSubtitleSearch} className="grid grid-cols-1 md:grid-cols-6 gap-2">
            <input
              type="text"
              value={subQuery}
              onChange={(e) => setSubQuery(e.target.value)}
              placeholder="Título o archivo..."
              className="md:col-span-2 bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white"
            />
            <input
              type="text"
              value={subImdb}
              onChange={(e) => setSubImdb(e.target.value)}
              placeholder="IMDb ID (ej. tt0903747)"
              className="bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white"
            />
            <input
              type="number"
              value={subSeason}
              onChange={(e) => setSubSeason(e.target.value)}
              placeholder="Temp."
              className="bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white"
            />
            <input
              type="number"
              value={subEpisode}
              onChange={(e) => setSubEpisode(e.target.value)}
              placeholder="Ep."
              className="bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white"
            />
            <div className="flex gap-2">
              <input
                type="text"
                value={subLangs}
                onChange={(e) => setSubLangs(e.target.value)}
                placeholder="es,en"
                className="w-20 bg-zinc-950 border border-zinc-800 rounded-xl px-2.5 py-2 text-xs text-white"
              />
              <button
                type="submit"
                disabled={subLoading}
                className="flex-1 bg-[#E50914] hover:bg-red-700 text-white text-xs font-bold rounded-xl px-3 py-2 cursor-pointer disabled:opacity-50"
              >
                {subLoading ? '...' : 'Buscar'}
              </button>
            </div>
          </form>

          {subResults.length > 0 && (
            <div className="divide-y divide-zinc-800/80 border border-zinc-800 rounded-xl overflow-hidden bg-zinc-950/60">
              {subResults.map((sub) => (
                <div key={sub.file_id} className="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="px-2 py-0.5 rounded bg-zinc-800 text-[10px] font-bold uppercase text-white">
                        {sub.language}
                      </span>
                      {sub.moviehash_match && (
                        <span className="px-2 py-0.5 rounded bg-emerald-950 text-emerald-300 border border-emerald-500/30 text-[10px] font-bold">
                          HASH SYNC
                        </span>
                      )}
                      {sub.hearing_impaired && (
                        <span className="px-2 py-0.5 rounded bg-zinc-900 text-zinc-400 text-[10px]">HI</span>
                      )}
                      <span className="text-[11px] text-amber-400 font-mono">★ {sub.ratings?.toFixed?.(1) ?? sub.ratings}</span>
                    </div>
                    <p className="text-xs font-semibold text-zinc-200 truncate mt-1">{sub.release || sub.file_name}</p>
                  </div>
                  <button
                    type="button"
                    disabled={downloadingSubId === sub.file_id}
                    onClick={() => handleSubtitleDownload(sub.file_id, sub.file_name)}
                    className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-xs font-bold text-white cursor-pointer disabled:opacity-50"
                  >
                    <DownloadCloud className="w-3.5 h-3.5 text-red-400" />
                    <span>{downloadingSubId === sub.file_id ? 'Guardando...' : 'Descargar .SRT'}</span>
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* TAB 4: MANTENIMIENTO, AUTO-UPDATE Y CACHÉ */}
      {subTab === 'maintenance' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="bg-zinc-950/70 border border-zinc-800 rounded-xl p-5 space-y-3">
            <h3 className="text-sm font-bold text-white flex items-center gap-2">
              <RefreshCw className="w-4 h-4 text-red-500" /> Auto-Actualización de Series (lib/service.py)
            </h3>
            <p className="text-xs text-zinc-400 leading-relaxed">
              Revisa las <strong>{status?.tracked_shows_count ?? 0} series</strong> vinculadas con <code className="text-zinc-300">tmdb_id</code> en la biblioteca de FlixHN e incorpora nuevos episodios emitidos sin sobrescribir rutas locales ni campos bloqueados.
            </p>
            <button
              type="button"
              disabled={updatingShows}
              onClick={handleUpdateTrackedShows}
              className="inline-flex items-center gap-2 px-4 py-2 bg-[#E50914] hover:bg-red-700 text-white text-xs font-bold rounded-xl cursor-pointer disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${updatingShows ? 'animate-spin' : ''}`} />
              <span>{updatingShows ? 'Sincronizando series...' : 'Actualizar Series Ahora'}</span>
            </button>
          </div>

          <div className="bg-zinc-950/70 border border-zinc-800 rounded-xl p-5 space-y-3">
            <h3 className="text-sm font-bold text-white flex items-center gap-2">
              <Trash2 className="w-4 h-4 text-sky-400" /> Caché de Metadatos TMDB
            </h3>
            <p className="text-xs text-zinc-400 leading-relaxed">
              Las consultas a TMDB se cachean durante <strong>{status?.tmdb?.cache_ttl_days ?? 7} días</strong> para minimizar latencia y consumo de cuota. Purga la caché si necesitas refrescar estrenos de inmediato.
            </p>
            <button
              type="button"
              disabled={clearingCache}
              onClick={handleClearCache}
              className="inline-flex items-center gap-2 px-4 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-white text-xs font-bold rounded-xl cursor-pointer disabled:opacity-50"
            >
              <Trash2 className="w-3.5 h-3.5 text-sky-400" />
              <span>{clearingCache ? 'Purgando...' : 'Purgar Caché TMDB'}</span>
            </button>
          </div>
        </div>
      )}

      {/* MODAL DE DETALLES TMDB + PROVEEDORES 2 FASES */}
      {selectedItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md">
          <div className="bg-[#141b2d] border border-zinc-800 rounded-2xl max-w-3xl w-full max-h-[88vh] overflow-y-auto shadow-2xl">
            <div className="flex items-center justify-between p-4 border-b border-zinc-800 sticky top-0 bg-[#141b2d]/95 backdrop-blur z-10">
              <h3 className="font-bold text-sm text-white truncate">{selectedItem.title}</h3>
              <button
                type="button"
                onClick={() => setSelectedItem(null)}
                className="p-1 text-zinc-400 hover:text-white cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {detailLoading ? (
              <div className="p-10 flex items-center justify-center gap-2 text-xs text-zinc-400">
                <RefreshCw className="w-4 h-4 animate-spin text-red-500" />
                <span>Cargando metadatos completos desde TMDB...</span>
              </div>
            ) : detailData?.details ? (
              <div className="p-5 space-y-5 text-xs">
                <div className="flex flex-col sm:flex-row gap-4">
                  {detailData.details.poster_url && (
                    <img
                      src={detailData.details.poster_url}
                      alt={detailData.details.title}
                      className="w-36 rounded-xl border border-zinc-800 shrink-0 self-start"
                    />
                  )}
                  <div className="space-y-2.5 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-base font-black text-white">{detailData.details.title}</span>
                      {detailData.details.release_year && (
                        <span className="px-2 py-0.5 rounded bg-zinc-900 text-zinc-300 font-mono">
                          {detailData.details.release_year}
                        </span>
                      )}
                      {detailData.details.imdb_id && (
                        <span className="px-2 py-0.5 rounded bg-amber-500/15 text-amber-300 font-mono">
                          {detailData.details.imdb_id}
                        </span>
                      )}
                    </div>
                    {detailData.details.tagline && (
                      <p className="italic text-zinc-400">“{detailData.details.tagline}”</p>
                    )}
                    <p className="text-zinc-300 leading-relaxed">{detailData.details.overview || 'Sin sinopsis disponible.'}</p>
                    <div className="flex flex-wrap gap-1.5">
                      {(detailData.details.genres || []).map((g) => (
                        <span key={g} className="px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-[10px] text-zinc-300">
                          {g}
                        </span>
                      ))}
                    </div>

                    <div className="flex flex-wrap gap-2 pt-2">
                      <button
                        type="button"
                        onClick={() => handleSyncToLibrary(selectedItem)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-[#E50914] hover:bg-red-700 text-white font-bold rounded-xl cursor-pointer"
                      >
                        <Plus className="w-3.5 h-3.5" />
                        <span>{detailData.in_library ? 'Actualizar en Biblioteca' : 'Sincronizar a Biblioteca'}</span>
                      </button>

                      <button
                        type="button"
                        onClick={() =>
                          handleProviderSearch({
                            method: detailData.details.type === 'series' ? 'search_show' : 'search_movie',
                            tmdb_id: detailData.details.tmdb_id,
                            title: detailData.details.original_title || detailData.details.title,
                            alternative_titles: detailData.details.alternative_titles || {},
                            year: detailData.details.release_year,
                          })
                        }
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-white font-bold rounded-xl cursor-pointer"
                      >
                        <Layers className="w-3.5 h-3.5 text-sky-400" />
                        <span>Buscar en Proveedores</span>
                      </button>

                      {detailData.details.trailer_youtube_key && (
                        <a
                          href={`https://www.youtube.com/watch?v=${detailData.details.trailer_youtube_key}`}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-red-400 font-bold rounded-xl"
                        >
                          <Youtube className="w-3.5 h-3.5" />
                          <span>Ver Tráiler</span>
                          <ExternalLink className="w-3 h-3" />
                        </a>
                      )}
                    </div>
                  </div>
                </div>

                {/* Resultados de proveedores dentro del modal */}
                {providerCandidates.length > 0 && (
                  <div className="space-y-2 pt-3 border-t border-zinc-800">
                    <h4 className="font-bold text-white">Candidatos encontrados por FlixProviderOrchestrator:</h4>
                    {providerCandidates.map((cand, idx) => (
                      <div
                        key={idx}
                        className="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-zinc-950/80 border border-zinc-800"
                      >
                        <div>
                          <p className="font-semibold text-white">{cand.label}</p>
                          <p className="text-[11px] text-zinc-400">{cand.label2}</p>
                        </div>
                        <button
                          type="button"
                          onClick={() => handleResolveCandidate(cand, `${detailData.details.type}:${detailData.details.tmdb_id}`)}
                          className="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold cursor-pointer"
                        >
                          Resolver
                        </button>
                      </div>
                    ))}
                  </div>
                )}

                {resolvedPayload && (
                  <div className="p-3 rounded-xl bg-black/50 border border-emerald-500/30">
                    <p className="font-bold text-emerald-400 mb-1">Enlace Resuelto:</p>
                    <pre className="text-[11px] text-zinc-300 overflow-x-auto font-mono">
                      {JSON.stringify(resolvedPayload, null, 2)}
                    </pre>
                  </div>
                )}

                {/* Temporadas si es serie */}
                {detailData.details.seasons?.length > 0 && (
                  <div className="pt-3 border-t border-zinc-800 space-y-2">
                    <h4 className="font-bold text-white">Temporadas ({detailData.details.seasons.length})</h4>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                      {detailData.details.seasons.map((s) => (
                        <div key={s.season_number} className="p-2.5 rounded-xl bg-zinc-950/70 border border-zinc-800">
                          <p className="font-bold text-zinc-200">{s.title}</p>
                          <p className="text-[11px] text-zinc-400">{s.episode_count} episodios • {s.air_date || '—'}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}
