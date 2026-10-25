import React, { useState, useEffect, useMemo, useCallback } from 'react';
import api from '../../services/api';
import {
  Film,
  Plus,
  Trash2,
  X,
  RefreshCw,
  Search,
  ChevronLeft,
  ChevronRight,
  Filter,
  Folder,
  FolderOpen,
  Home,
  CornerLeftUp,
  Play,
  Edit2,
  Tv,
} from 'lucide-react';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';

const CatalogManagement = ({ titles = [], onRefresh }) => {
  const [showAddTitle, setShowAddTitle] = useState(false);
  const [showAddEpisode, setShowAddEpisode] = useState(null); // title object
  const [showEditTitle, setShowEditTitle] = useState(null); // title object for editing
  const [previewVideo, setPreviewVideo] = useState(null); // title object for playback
  const [loading, setLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);

  // Estados de navegación por carpetas y subcarpetas
  const [currentFolder, setCurrentFolder] = useState(() => {
    if (typeof window !== 'undefined') {
      return new URLSearchParams(window.location.search).get('folder') || '';
    }
    return '';
  });
  const [parentFolder, setParentFolder] = useState(null);
  const [folders, setFolders] = useState([]);
  const [breadcrumbs, setBreadcrumbs] = useState([{ name: 'Inicio', path: '' }]);
  const [folderTitles, setFolderTitles] = useState([]);
  const [loadingFolder, setLoadingFolder] = useState(false);

  // Cargar contenido de la carpeta solicitada desde la API
  const loadFolder = useCallback(async (path = '') => {
    setLoadingFolder(true);
    try {
      const res = await api.get('/admin/titles', {
        params: { path: path },
      });
      const data = res?.data || {};
      setFolders(data.folders || []);
      setFolderTitles(data.titles || []);
      setBreadcrumbs(data.breadcrumbs || [{ name: 'Inicio', path: '' }]);
      setCurrentFolder(data.current_folder !== undefined ? data.current_folder : path);
      setParentFolder(data.parent_folder !== undefined ? data.parent_folder : null);

      if (typeof window !== 'undefined') {
        const url = new URL(window.location.href);
        if (path) {
          url.searchParams.set('folder', path);
        } else {
          url.searchParams.delete('folder');
        }
        window.history.replaceState({}, '', url.toString());
      }
    } catch (err) {
      console.error('Error al cargar contenido de la carpeta:', err);
    } finally {
      setLoadingFolder(false);
    }
  }, []);

  // Escuchar botón Atrás/Adelante del navegador
  useEffect(() => {
    const handlePopState = () => {
      const p = new URLSearchParams(window.location.search).get('folder') || '';
      loadFolder(p);
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [loadFolder]);

  // Carga inicial
  useEffect(() => {
    const initialFolder = new URLSearchParams(window.location.search).get('folder') || '';
    loadFolder(initialFolder);
  }, [loadFolder]);

  const handleOpenFolder = (folderPath) => {
    loadFolder(folderPath);
  };

  const handleGoUp = () => {
    if (parentFolder !== null) {
      loadFolder(parentFolder);
    } else if (currentFolder) {
      const parts = currentFolder.split('/');
      parts.pop();
      loadFolder(parts.join('/'));
    }
  };

  // Formulario Nuevo Título
  const [newTitle, setNewTitle] = useState({
    name: '',
    type: 'movie',
    description: '',
    poster_url: '',
    backdrop_url: '',
    release_year: 2024,
    genre: 'Acción',
    is_featured: false,
    stream_path: '',
    duration_seconds: 600,
  });

  // Formulario Nuevo Episodio
  const [newEpisode, setNewEpisode] = useState({
    season_id: '',
    episode_number: 1,
    title: '',
    description: '',
    stream_path: '',
    duration_seconds: 600,
    thumbnail_url: '',
  });

  const handleCreateTitle = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('/admin/titles', newTitle);
      setShowAddTitle(false);
      setNewTitle({
        name: '',
        type: 'movie',
        description: '',
        poster_url: '',
        backdrop_url: '',
        release_year: 2024,
        genre: 'Acción',
        is_featured: false,
        stream_path: '',
        duration_seconds: 600,
      });
      await loadFolder(currentFolder);
      if (onRefresh) onRefresh();
      notifyCatalogUpdated({ action: 'title_created' });
    } catch (err) {
      console.error('Error al guardar título:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleUpdateTitle = async (e) => {
    e.preventDefault();
    if (!showEditTitle) return;
    setLoading(true);
    try {
      await api.put(`/admin/titles/${showEditTitle.id}`, showEditTitle);
      const updatedId = showEditTitle.id;
      setShowEditTitle(null);
      await loadFolder(currentFolder);
      if (onRefresh) onRefresh();
      notifyCatalogUpdated({ action: 'title_updated', titleId: updatedId });
    } catch (err) {
      console.error('Error actualizando título:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleDeleteTitle = async (id) => {
    try {
      await api.delete(`/admin/titles/${id}`);
      await loadFolder(currentFolder);
      if (onRefresh) onRefresh();
      notifyCatalogUpdated({ action: 'title_deleted', titleId: id });
    } catch (err) {
      console.error('Error eliminando título:', err);
    }
  };

  const handleAddEpisodeSubmit = async (e) => {
    e.preventDefault();
    try {
      await api.post(`/admin/titles/${showAddEpisode.id}/episodes`, newEpisode);
      const titleId = showAddEpisode.id;
      setShowAddEpisode(null);
      await loadFolder(currentFolder);
      if (onRefresh) onRefresh();
      notifyCatalogUpdated({ action: 'episode_added', titleId });
    } catch (err) {
      console.error('Error agregando episodio:', err);
    }
  };

  const [mediaServerUrl, setMediaServerUrl] = useState('http://45.4.87.126:6789');
  const [mediaApiKey, setMediaApiKey] = useState('');
  const [syncingRemote, setSyncingRemote] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);

  // Filtros y Paginación
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');
  const [currentPage, setCurrentPage] = useState(1);
  const pageSize = 50;

  // Precargar URL y API Key guardadas en la configuración del servidor
  useEffect(() => {
    let isMounted = true;
    api.get('/admin/network-settings')
      .then((res) => {
        if (!isMounted) return;
        const s = res?.data?.settings || res?.data?.data || res?.data || {};
        if (s.media_server_url || s.emby_server_url) {
          setMediaServerUrl(s.media_server_url || s.emby_server_url);
        }
        if (s.media_server_api_key || s.emby_api_key) {
          setMediaApiKey(s.media_server_api_key || s.emby_api_key);
        }
      })
      .catch((err) => {
        console.warn('No se pudo precargar la configuración del servidor:', err);
      });
    return () => {
      isMounted = false;
    };
  }, []);

  // Títulos mostrados para la carpeta activa
  const activeTitles = folderTitles.length > 0 || currentFolder !== '' ? folderTitles : (titles || []);

  const filteredTitles = useMemo(() => {
    return (activeTitles || []).filter((t) => {
      const matchType = typeFilter === 'all' || t.type === typeFilter;
      const s = searchTerm.trim().toLowerCase();
      const matchSearch = !s ||
        (t.name && t.name.toLowerCase().includes(s)) ||
        (t.genre && t.genre.toLowerCase().includes(s)) ||
        (t.release_year && String(t.release_year).includes(s));
      return matchType && matchSearch;
    });
  }, [activeTitles, typeFilter, searchTerm]);

  const totalPages = Math.max(1, Math.ceil(filteredTitles.length / pageSize));
  const paginatedTitles = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return filteredTitles.slice(start, start + pageSize);
  }, [filteredTitles, currentPage, pageSize]);

  useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, typeFilter, currentFolder]);

  const handleSyncRemote = async () => {
    setSyncingRemote(true);
    setSyncStatus(null);
    try {
      const res = await api.post('/admin/media/sync', {
        server_url: mediaServerUrl,
        api_key: mediaApiKey,
      });
      if (res.data?.status === 'unauthorized' || res.data?.success === false) {
        setSyncStatus({
          type: res.data?.status === 'unauthorized' ? 'warning' : 'error',
          message: res.data.message || 'Error al conectar con el servidor remoto. Verifique la IP y la API Key.',
        });
      } else {
        setSyncStatus({
          type: 'success',
          message: res.data.message || `Sincronización completada exitosamente. Total títulos: ${res.data.total_synced || 0}`,
        });
        if (onRefresh) onRefresh();
      }
    } catch (err) {
      setSyncStatus({
        type: 'error',
        message: err.response?.data?.message || 'Error de comunicación con el servidor de medios.',
      });
    } finally {
      setSyncingRemote(false);
    }
  };

  const handleSync = async () => {
    setSyncing(true);
    setSyncStatus(null);
    try {
      const res = await api.post('/admin/media/sync', {
        server_url: mediaServerUrl,
        api_key: mediaApiKey,
      });
      setSyncStatus({
        type: res.data?.success ? 'success' : 'warning',
        message: res.data?.message || 'Sincronización de medios completada exitosamente.',
      });
      if (onRefresh) onRefresh();
    } catch (err) {
      setSyncStatus({
        type: 'error',
        message: err.response?.data?.message || 'Error al sincronizar con el servidor de medios.',
      });
    } finally {
      setSyncing(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl md:text-2xl font-black text-gray-900 dark:text-white tracking-wide flex items-center gap-2">
            <Film className="w-6 h-6 text-[#E50914]" />
            Biblioteca / Catálogo Local
          </h2>
          <p className="text-xs text-gray-500 dark:text-zinc-400">
            Administración de películas, series, metadatos y rutas de streaming On-Net
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => setShowAddTitle(true)}
            className="bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded-lg text-xs font-bold flex items-center gap-2 transition-all shadow-md active:scale-95"
          >
            <Plus className="w-4 h-4" />
            Agregar Película o Serie
          </button>
        </div>
      </div>

      {/* Tarjeta de Conexión y Sincronización Servidor de Medios Remoto */}
      <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-5 shadow-sm space-y-4 transition-colors">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 dark:border-zinc-800/80 pb-3">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/40 rounded-lg text-[#E50914]">
              <RefreshCw className="w-4 h-4" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-gray-900 dark:text-white">
                Sincronización con Servidor de Medios (IP Remota)
              </h3>
              <p className="text-[11px] text-gray-500 dark:text-zinc-400">
                Descarga automáticamente categorías, títulos, carátulas y streams adaptativos HLS DirectPlay
              </p>
            </div>
          </div>
          <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-gray-100 dark:bg-zinc-800 text-gray-600 dark:text-zinc-300 border border-gray-200 dark:border-zinc-700">
            DirectPlay / HLS • Ultra HD
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
          <div className="md:col-span-6 space-y-1">
            <label className="text-[11px] font-semibold text-gray-600 dark:text-zinc-400">
              IP y Puerto del Servidor de Medios
            </label>
            <input
              type="text"
              value={mediaServerUrl}
              onChange={(e) => setMediaServerUrl(e.target.value)}
              placeholder="http://45.4.87.126:6789"
              className="w-full px-3 py-2 text-xs rounded-lg bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 text-gray-900 dark:text-white font-mono focus:ring-1 focus:ring-[#E50914] focus:outline-none"
            />
          </div>

          <div className="md:col-span-3 space-y-1">
            <label className="text-[11px] font-semibold text-gray-600 dark:text-zinc-400">
              API Key / Token de Acceso
            </label>
            <input
              type="password"
              value={mediaApiKey}
              onChange={(e) => setMediaApiKey(e.target.value)}
              placeholder="Token de Acceso Remoto"
              className="w-full px-3 py-2 text-xs rounded-lg bg-gray-50 dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 text-gray-900 dark:text-white font-mono focus:ring-1 focus:ring-[#E50914] focus:outline-none"
            />
          </div>

          <div className="md:col-span-3 flex items-center gap-2">
            <button
              onClick={handleSyncRemote}
              disabled={syncingRemote || syncing}
              className="flex-1 bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-2 transition-all shadow-md active:scale-95 disabled:opacity-60 cursor-pointer"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${syncingRemote ? 'animate-spin' : ''}`} />
              {syncingRemote ? 'Sincronizando...' : 'Sincronizar Catálogo'}
            </button>
          </div>
        </div>

        {/* Notificación de estado */}
        {syncStatus && (
          <div
            className={`p-3 rounded-lg text-xs flex items-start gap-2.5 transition-all ${
              syncStatus.type === 'success'
                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60'
                : syncStatus.type === 'warning'
                ? 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60'
                : 'bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-300 border border-red-200 dark:border-red-800/60'
            }`}
          >
            <span className="font-semibold capitalize">{syncStatus.type}:</span>
            <span className="flex-1">{syncStatus.message}</span>
          </div>
        )}
      </div>

      {/* Barra de Migas de Pan (Breadcrumbs) y Navegación de Directorios */}
      <div className="bg-[#181818] border border-zinc-800 rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3 shadow-sm">
        {/* Breadcrumbs */}
        <div className="flex items-center flex-wrap gap-1.5 text-xs">
          <button
            onClick={() => loadFolder('')}
            className={`flex items-center gap-1.5 px-2.5 py-1 rounded-md transition-colors ${
              currentFolder === ''
                ? 'bg-red-500/10 text-[#E50914] font-bold border border-red-500/20'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800'
            }`}
            title="Ir a la raíz de la biblioteca"
          >
            <Home className="w-3.5 h-3.5" />
            <span>Inicio</span>
          </button>

          {breadcrumbs.filter((b) => b.path !== '').map((crumb, idx) => {
            const isLast = idx === breadcrumbs.filter((b) => b.path !== '').length - 1;
            return (
              <React.Fragment key={crumb.path || idx}>
                <ChevronRight className="w-3.5 h-3.5 text-zinc-600 shrink-0" />
                <button
                  onClick={() => loadFolder(crumb.path)}
                  disabled={isLast}
                  className={`px-2.5 py-1 rounded-md transition-colors truncate max-w-[200px] ${
                    isLast
                      ? 'bg-red-500/10 text-[#E50914] font-bold border border-red-500/20 cursor-default'
                      : 'text-zinc-400 hover:text-white hover:bg-zinc-800 cursor-pointer'
                  }`}
                  title={crumb.name}
                >
                  {crumb.name}
                </button>
              </React.Fragment>
            );
          })}
        </div>

        {/* Acciones de Navegación y Control de Nivel */}
        <div className="flex items-center gap-2">
          {currentFolder !== '' && (
            <button
              onClick={handleGoUp}
              className="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 border border-zinc-700 px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm active:scale-95 cursor-pointer"
              title="Subir al directorio superior"
            >
              <CornerLeftUp className="w-3.5 h-3.5 text-[#E50914]" />
              <span>Subir un nivel</span>
            </button>
          )}

          <button
            onClick={() => loadFolder(currentFolder)}
            disabled={loadingFolder}
            className="p-1.5 bg-zinc-800/80 hover:bg-zinc-700 text-zinc-400 hover:text-white rounded-lg border border-zinc-700/60 transition-colors cursor-pointer"
            title="Recargar directorio actual"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loadingFolder ? 'animate-spin text-[#E50914]' : ''}`} />
          </button>
        </div>
      </div>

      {/* Sección de Carpetas y Subcarpetas Disponibles */}
      {folders.length > 0 && (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-400 flex items-center gap-2">
              <Folder className="w-4 h-4 text-amber-500" />
              Carpetas ({folders.length})
            </h3>
            <span className="text-[11px] text-zinc-500 font-mono">
              Haz clic en una carpeta para explorar su contenido
            </span>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            {folders.map((f, idx) => (
              <div
                key={f.path || f.relative_path || idx}
                onClick={() => handleOpenFolder(f.path || f.relative_path)}
                className="group bg-[#181818] hover:bg-zinc-850 border border-zinc-800 hover:border-[#E50914]/50 rounded-xl p-3.5 transition-all duration-200 cursor-pointer shadow-sm hover:shadow-md flex flex-col justify-between gap-2.5 active:scale-[0.98]"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="p-2.5 rounded-lg bg-amber-500/10 text-amber-500 group-hover:bg-[#E50914]/10 group-hover:text-[#E50914] transition-colors">
                    <Folder className="w-5 h-5 fill-amber-500/20 group-hover:fill-[#E50914]/20 transition-colors" />
                  </div>
                  <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-400">
                    {f.items_count} items
                  </span>
                </div>

                <div className="min-w-0">
                  <h4 className="font-bold text-xs text-white group-hover:text-[#E50914] truncate transition-colors" title={f.name}>
                    {f.name}
                  </h4>
                  <p className="text-[10px] text-zinc-500 truncate font-mono">
                    {f.relative_path || f.path}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Barra de Filtros y Búsqueda */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-[#181818] p-3 rounded-xl border border-zinc-800">
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="Buscar por título, género o año..."
            className="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg bg-zinc-900 border border-zinc-800 text-white placeholder-zinc-500 focus:outline-none focus:border-[#E50914]"
          />
        </div>

        <div className="flex items-center gap-2">
          <div className="flex bg-zinc-900 rounded-lg p-0.5 border border-zinc-800 text-xs">
            <button
              onClick={() => setTypeFilter('all')}
              className={`px-3 py-1 rounded-md font-medium transition-colors ${
                typeFilter === 'all' ? 'bg-[#E50914] text-white' : 'text-zinc-400 hover:text-white'
              }`}
            >
              Todos ({(titles || []).length})
            </button>
            <button
              onClick={() => setTypeFilter('movie')}
              className={`px-3 py-1 rounded-md font-medium transition-colors ${
                typeFilter === 'movie' ? 'bg-[#E50914] text-white' : 'text-zinc-400 hover:text-white'
              }`}
            >
              Películas
            </button>
            <button
              onClick={() => setTypeFilter('series')}
              className={`px-3 py-1 rounded-md font-medium transition-colors ${
                typeFilter === 'series' ? 'bg-[#E50914] text-white' : 'text-zinc-400 hover:text-white'
              }`}
            >
              Series
            </button>
          </div>
          <span className="text-[11px] text-zinc-400 font-mono hidden md:inline ml-2">
            {filteredTitles.length} títulos
          </span>
        </div>
      </div>

      {/* Tabla de Títulos */}
      <div className="overflow-x-auto admin-card bg-[#181818] rounded-xl border border-zinc-800 shadow-xl transition-colors">
        <table className="w-full text-left text-xs">
          <thead className="bg-gray-50 dark:bg-zinc-900/90 text-gray-500 dark:text-zinc-400 uppercase border-b border-gray-200 dark:border-zinc-800 font-semibold tracking-wider">
            <tr>
              <th className="p-3.5">Título</th>
              <th className="p-3.5">Tipo</th>
              <th className="p-3.5">Género / Año</th>
              <th className="p-3.5">Destacado</th>
              <th className="p-3.5">Ruta de Streaming</th>
              <th className="p-3.5 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-zinc-800/80">
            {paginatedTitles.length > 0 ? (
              paginatedTitles.map((t) => (
                <tr key={t.id} className="hover:bg-gray-50 dark:hover:bg-zinc-800/40 transition-colors">
                  <td className="p-3.5 flex items-center gap-3">
                    <img
                      src={t.poster_url}
                      alt={t.name}
                      className="w-9 h-12 object-cover rounded bg-gray-100 dark:bg-zinc-800"
                      onError={(e) => {
                        e.target.onerror = null;
                        e.target.src = '/img/placeholder-poster.png';
                      }}
                    />
                    <div className="min-w-0">
                      <p className="font-bold text-gray-900 dark:text-white text-sm truncate max-w-xs">{t.name}</p>
                      <p className="text-gray-500 dark:text-zinc-400 truncate max-w-xs text-[11px]">{t.description}</p>
                    </div>
                  </td>
                  <td className="p-3.5">
                    <span className={`px-2 py-0.5 rounded font-mono uppercase text-[10px] font-semibold ${
                      t.type === 'movie'
                        ? 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800/60'
                        : 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60'
                    }`}>
                      {t.type}
                    </span>
                  </td>
                  <td className="p-3.5 text-gray-600 dark:text-zinc-300">
                    {t.genre} • {t.release_year}
                  </td>
                  <td className="p-3.5">
                    {t.is_featured ? (
                      <span className="text-amber-500 dark:text-amber-400 font-bold flex items-center gap-1">★ Sí</span>
                    ) : (
                      <span className="text-gray-400 dark:text-zinc-500">No</span>
                    )}
                  </td>
                  <td className="p-3.5 font-mono text-[11px] text-gray-500 dark:text-zinc-400 truncate max-w-xs">
                    {t.type === 'movie' ? (t.stream_path || 'Sin ruta') : `${t.seasons?.length || 0} Temporada(s)`}
                  </td>
                  <td className="p-3.5 text-right space-x-1.5 whitespace-nowrap">
                    {(t.stream_path || t.stream_url) && (
                      <button
                        onClick={() => setPreviewVideo(t)}
                        className="text-emerald-500 hover:text-emerald-400 p-1.5 rounded hover:bg-emerald-950/40 transition-colors cursor-pointer"
                        title="Reproducir / Previsualizar video"
                      >
                        <Play className="w-4 h-4 inline" />
                      </button>
                    )}
                    <button
                      onClick={() => setShowEditTitle({ ...t })}
                      className="text-blue-500 hover:text-blue-400 p-1.5 rounded hover:bg-blue-950/40 transition-colors cursor-pointer"
                      title="Editar título"
                    >
                      <Edit2 className="w-4 h-4 inline" />
                    </button>
                    {t.type === 'series' && (
                      <button
                        onClick={() => {
                          setShowAddEpisode(t);
                          setNewEpisode((prev) => ({
                            ...prev,
                            season_id: t.seasons?.[0]?.id || '',
                          }));
                        }}
                        className="text-purple-700 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-semibold text-xs px-2 py-1 rounded bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800/60 transition-colors cursor-pointer"
                        title="Gestionar Episodios"
                      >
                        + Episodio
                      </button>
                    )}
                    <button
                      onClick={() => handleDeleteTitle(t.id)}
                      className="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer"
                      title="Eliminar título"
                    >
                      <Trash2 className="w-4 h-4 inline" />
                    </button>
                  </td>
                </tr>
              ))
            ) : (
              <tr>
                <td colSpan="6" className="p-8 text-center text-gray-400 dark:text-zinc-500 text-xs">
                  {searchTerm || typeFilter !== 'all'
                    ? 'No se encontraron títulos que coincidan con los filtros aplicados.'
                    : (folders.length > 0
                        ? 'Esta carpeta contiene subcarpetas. Haz clic en una carpeta arriba para explorar los títulos multimedia.'
                        : 'No hay títulos registrados en esta ubicación.')}
                </td>
              </tr>
            )}
          </tbody>
        </table>

        {/* Paginación */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between p-3.5 border-t border-zinc-800 text-xs text-zinc-400">
            <div>
              Mostrando {Math.min((currentPage - 1) * pageSize + 1, filteredTitles.length)} a{' '}
              {Math.min(currentPage * pageSize, filteredTitles.length)} de {filteredTitles.length} títulos
            </div>
            <div className="flex items-center gap-2">
              <button
                disabled={currentPage === 1}
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                className="px-2.5 py-1 rounded bg-zinc-900 border border-zinc-800 disabled:opacity-40 hover:bg-zinc-800 text-white flex items-center gap-1 cursor-pointer"
              >
                <ChevronLeft className="w-3.5 h-3.5" /> Anterior
              </button>
              <span className="font-mono text-zinc-300 px-2">
                Pág. {currentPage} de {totalPages}
              </span>
              <button
                disabled={currentPage >= totalPages}
                onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                className="px-2.5 py-1 rounded bg-zinc-900 border border-zinc-800 disabled:opacity-40 hover:bg-zinc-800 text-white flex items-center gap-1 cursor-pointer"
              >
                Siguiente <ChevronRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Modal Agregar Título */}
      {showAddTitle && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 rounded-xl max-w-lg w-full p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-gray-200 dark:border-zinc-800 pb-3">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white">Nuevo Título en Catálogo</h3>
              <button onClick={() => setShowAddTitle(false)} className="text-gray-400 dark:text-zinc-400 hover:text-gray-900 dark:hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateTitle} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Nombre del Título</label>
                <input
                  type="text"
                  required
                  value={newTitle.name}
                  onChange={(e) => setNewTitle({ ...newTitle, name: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  placeholder="Ej: Inception (Origen)"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Tipo</label>
                  <select
                    value={newTitle.type}
                    onChange={(e) => setNewTitle({ ...newTitle, type: e.target.value })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  >
                    <option value="movie">Película</option>
                    <option value="series">Serie</option>
                  </select>
                </div>
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Año de Estreno</label>
                  <input
                    type="number"
                    required
                    value={newTitle.release_year}
                    onChange={(e) => setNewTitle({ ...newTitle, release_year: parseInt(e.target.value) || 2024 })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  />
                </div>
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Género</label>
                <input
                  type="text"
                  required
                  value={newTitle.genre}
                  onChange={(e) => setNewTitle({ ...newTitle, genre: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  placeholder="Acción, Drama, Sci-Fi..."
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Descripción / Sinopsis</label>
                <textarea
                  required
                  rows="2"
                  value={newTitle.description}
                  onChange={(e) => setNewTitle({ ...newTitle, description: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  placeholder="Sinopsis concisa..."
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">URL de Portada (Poster)</label>
                <input
                  type="text"
                  required
                  value={newTitle.poster_url}
                  onChange={(e) => setNewTitle({ ...newTitle, poster_url: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                  placeholder="https://... o /storage/posters/..."
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">URL de Backdrop (Hero / Fondo)</label>
                <input
                  type="text"
                  required
                  value={newTitle.backdrop_url}
                  onChange={(e) => setNewTitle({ ...newTitle, backdrop_url: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                  placeholder="https://... o /storage/backdrops/..."
                />
              </div>

              {newTitle.type === 'movie' && (
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Ruta de Streaming HLS / MP4</label>
                  <input
                    type="text"
                    value={newTitle.stream_path}
                    onChange={(e) => setNewTitle({ ...newTitle, stream_path: e.target.value })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                    placeholder="ej: /media/movies/archivo.mp4 o .m3u8"
                  />
                </div>
              )}

              <div className="flex items-center gap-2 pt-2">
                <input
                  type="checkbox"
                  id="is_featured"
                  checked={newTitle.is_featured}
                  onChange={(e) => setNewTitle({ ...newTitle, is_featured: e.target.checked })}
                  className="rounded bg-gray-50 dark:bg-zinc-900 border-gray-300 dark:border-zinc-700 text-[#E50914] focus:ring-0"
                />
                <label htmlFor="is_featured" className="text-gray-700 dark:text-zinc-300 font-medium">
                  Destacar en el Billboard Principal (Banner Hero)
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowAddTitle(false)}
                  className="px-4 py-2 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 rounded text-gray-700 dark:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={loading}
                  className="px-4 py-2 bg-[#E50914] hover:bg-[#F40612] rounded text-white font-bold disabled:opacity-50 transition-colors shadow-sm"
                >
                  {loading ? 'Guardando...' : 'Guardar Título'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Agregar Episodio */}
      {showAddEpisode && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between border-b border-gray-200 dark:border-zinc-800 pb-3">
              <h3 className="font-bold text-base text-gray-900 dark:text-white">
                Agregar Episodio a "{showAddEpisode.name}"
              </h3>
              <button onClick={() => setShowAddEpisode(null)} className="text-gray-400 dark:text-zinc-400 hover:text-gray-900 dark:hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleAddEpisodeSubmit} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Temporada</label>
                <select
                  required
                  value={newEpisode.season_id}
                  onChange={(e) => setNewEpisode({ ...newEpisode, season_id: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                >
                  <option value="">Selecciona Temporada...</option>
                  {(showAddEpisode?.seasons || []).map((s) => (
                    <option key={s.id} value={s.id}>
                      Temporada {s.season_number} {s.title ? `(${s.title})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Nº Episodio</label>
                  <input
                    type="number"
                    required
                    min="1"
                    value={newEpisode.episode_number}
                    onChange={(e) => setNewEpisode({ ...newEpisode, episode_number: parseInt(e.target.value) || 1 })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  />
                </div>
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Duración (seg)</label>
                  <input
                    type="number"
                    required
                    min="1"
                    value={newEpisode.duration_seconds}
                    onChange={(e) => setNewEpisode({ ...newEpisode, duration_seconds: parseInt(e.target.value) || 600 })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  />
                </div>
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Título del Episodio</label>
                <input
                  type="text"
                  required
                  value={newEpisode.title}
                  onChange={(e) => setNewEpisode({ ...newEpisode, title: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  placeholder="Ej: Capítulo 1: El Comienzo"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Ruta HLS (.m3u8) o MP4</label>
                <input
                  type="text"
                  required
                  value={newEpisode.stream_path}
                  onChange={(e) => setNewEpisode({ ...newEpisode, stream_path: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white font-mono focus:outline-none focus:border-[#E50914]"
                  placeholder="/media/series/.../index.m3u8"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowAddEpisode(null)}
                  className="px-4 py-2 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 rounded text-gray-700 dark:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#E50914] hover:bg-[#F40612] rounded text-white font-bold transition-colors shadow-sm"
                >
                  Guardar Episodio
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {/* Modal Editar Título */}
      {showEditTitle && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 rounded-xl max-w-lg w-full p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-gray-200 dark:border-zinc-800 pb-3">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white flex items-center gap-2">
                <Edit2 className="w-5 h-5 text-blue-500" />
                Editar Título
              </h3>
              <button onClick={() => setShowEditTitle(null)} className="text-gray-400 dark:text-zinc-400 hover:text-gray-900 dark:hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleUpdateTitle} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Nombre del Título</label>
                <input
                  type="text"
                  required
                  value={showEditTitle.name || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, name: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Tipo</label>
                  <select
                    value={showEditTitle.type || 'movie'}
                    onChange={(e) => setShowEditTitle({ ...showEditTitle, type: e.target.value })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  >
                    <option value="movie">Película</option>
                    <option value="series">Serie</option>
                  </select>
                </div>
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Año de Estreno</label>
                  <input
                    type="number"
                    required
                    value={showEditTitle.release_year || 2024}
                    onChange={(e) => setShowEditTitle({ ...showEditTitle, release_year: parseInt(e.target.value) || 2024 })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  />
                </div>
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Género</label>
                <input
                  type="text"
                  required
                  value={showEditTitle.genre || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, genre: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Descripción / Sinopsis</label>
                <textarea
                  required
                  rows="2"
                  value={showEditTitle.description || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, description: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">URL de Portada (Poster)</label>
                <input
                  type="text"
                  required
                  value={showEditTitle.poster_url || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, poster_url: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">URL de Backdrop (Hero / Fondo)</label>
                <input
                  type="text"
                  required
                  value={showEditTitle.backdrop_url || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, backdrop_url: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Ruta de Streaming HLS / MP4</label>
                <input
                  type="text"
                  value={showEditTitle.stream_path || ''}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, stream_path: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914] font-mono"
                />
              </div>

              <div className="flex items-center gap-2 pt-2">
                <input
                  type="checkbox"
                  id="edit_is_featured"
                  checked={!!showEditTitle.is_featured}
                  onChange={(e) => setShowEditTitle({ ...showEditTitle, is_featured: e.target.checked })}
                  className="rounded bg-gray-50 dark:bg-zinc-900 border-gray-300 dark:border-zinc-700 text-[#E50914] focus:ring-0"
                />
                <label htmlFor="edit_is_featured" className="text-gray-700 dark:text-zinc-300 font-medium">
                  Destacar en el Billboard Principal (Banner Hero)
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowEditTitle(null)}
                  className="px-4 py-2 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 rounded text-gray-700 dark:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={loading}
                  className="px-4 py-2 bg-[#E50914] hover:bg-[#F40612] rounded text-white font-bold disabled:opacity-50 transition-colors shadow-sm"
                >
                  {loading ? 'Guardando...' : 'Guardar Cambios'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Previsualización de Video */}
      {previewVideo && (
        <div className="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-4xl w-full p-4 space-y-3 shadow-2xl relative">
            <div className="flex items-center justify-between pb-2 border-b border-zinc-800">
              <div className="flex items-center gap-2">
                <Film className="w-5 h-5 text-[#E50914]" />
                <h3 className="font-bold text-white text-sm">{previewVideo.name}</h3>
                <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-zinc-800 text-zinc-300 border border-zinc-700">
                  {previewVideo.type}
                </span>
              </div>
              <button
                onClick={() => setPreviewVideo(null)}
                className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="aspect-video bg-black rounded-lg overflow-hidden flex items-center justify-center border border-zinc-800/80">
              {previewVideo.stream_url || previewVideo.stream_path ? (
                <video
                  src={previewVideo.stream_url || previewVideo.stream_path}
                  controls
                  autoPlay
                  className="w-full h-full object-contain"
                />
              ) : (
                <p className="text-xs text-zinc-500">Sin ruta de streaming válida</p>
              )}
            </div>

            <div className="text-[11px] text-zinc-400 flex items-center justify-between pt-1">
              <span className="font-mono truncate max-w-md">
                {previewVideo.stream_path || previewVideo.stream_url}
              </span>
              <button
                onClick={() => setPreviewVideo(null)}
                className="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-white text-xs font-medium cursor-pointer"
              >
                Cerrar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default CatalogManagement;
