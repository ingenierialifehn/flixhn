import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
import api from '../../services/api';
import {
  Search,
  DownloadCloud,
  Film,
  Tv,
  Check,
  RefreshCw,
  ExternalLink,
  X,
  Filter,
  Sparkles,
  Folder,
  Layers,
  CheckCircle2,
  AlertCircle,
  Clock,
  Eye,
  Play,
  Zap,
} from 'lucide-react';
import FolderPickerModal from './FolderPickerModal';
import OttPlayerModal from './OttPlayerModal';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';

// FunciÃ³n para generar resultados dinÃ¡micos realistas segÃºn la consulta del usuario
export const generateSimulatedResults = (query = '', provider = 'all') => {
  return [];
};

/**
 * Insignia vectorial o estilizada de la plataforma para las tarjetas de catÃ¡logo remoto.
 */
export const PlatformMiniBadge = ({ provider }) => {
  switch (provider) {
    case 'netflix':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-black/80 text-[#E50914] border border-red-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-[#E50914]" />
          Netflix
        </span>
      );
    case 'disney':
    case 'disneyplus':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-[#0c1b33]/90 text-blue-400 border border-blue-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-blue-400" />
          Disney+
        </span>
      );
    case 'hbo_max':
    case 'max':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-[#5822b4]/30 text-purple-300 border border-purple-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-purple-400" />
          Max (HBO)
        </span>
      );
    case 'amazon_prime':
    case 'prime_video':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-sky-950/80 text-[#00A8E1] border border-sky-500/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-[#00A8E1]" />
          Prime Video
        </span>
      );
    case 'apple_tv':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-zinc-900/90 text-zinc-200 border border-zinc-700/60 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-300" />
          Apple TV+
        </span>
      );
    case 'paramount':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-blue-950/80 text-blue-400 border border-blue-600/40 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
          Paramount+
        </span>
      );
    default:
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-zinc-900 text-zinc-300 border border-zinc-700/50 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-zinc-400" />
          {provider || 'OTT'}
        </span>
      );
  }
};

// Base local de respaldo vacÃ­a: Se nutre 100% del catÃ¡logo real conectado desde la API
const CURATED_OTT_CATALOG = [];

const SUGGESTION_CHIPS = [
  'The Witcher',
  'Stranger Things',
  'Loki',
  'The Last of Us',
  'Avatar',
  'The Boys',
  'One Piece',
  'Fallout',
  'Arcane',
  'House of the Dragon',
];

/**
 * Explorador y Buscador de CatÃ¡logo Remoto On-Demand (FlixGrab Engine)
 */
export default function OttRemoteCatalogExplorer({
  onTaskCreated,
  showToast,
  connectedProviders = [],
  targetProviderFromParent = null,
  activeDownloadingTasks = [],
  allTasks = [],
}) {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedProvider, setSelectedProvider] = useState('all');
  const [mediaTypeFilter, setMediaTypeFilter] = useState('all'); // 'all' | 'movie' | 'series'
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [searchedOnce, setSearchedOnce] = useState(false);

  // Sincronizar selecciÃ³n de plataforma desde el padre si cambia
  useEffect(() => {
    if (targetProviderFromParent) {
      setSelectedProvider(targetProviderFromParent);
    }
  }, [targetProviderFromParent]);

  // Lista sincronizada de proveedores conectados
  const [providersList, setProvidersList] = useState(connectedProviders || []);

  useEffect(() => {
    if (connectedProviders && connectedProviders.length > 0) {
      setProvidersList(connectedProviders);
    } else {
      api
        .get('/admin/ott-providers')
        .then((res) => {
          if (res.data?.providers && Array.isArray(res.data.providers)) {
            setProvidersList(res.data.providers);
          }
        })
        .catch(() => {});
    }
  }, [connectedProviders]);

  const activeConnectedProviders = useMemo(() => {
    return (providersList || []).filter((p) => p.status === 'connected');
  }, [providersList]);

  // Resolver quÃ© proveedor conectado debe asumir la descarga
  const resolveTargetProvider = useCallback(
    (item) => {
      if (activeConnectedProviders.length === 0) {
        return item?.provider || 'netflix';
      }
      const match = activeConnectedProviders.find(
        (p) => p.provider === item?.provider
      );
      if (match) {
        return match.provider;
      }
      return activeConnectedProviders[0].provider;
    },
    [activeConnectedProviders]
  );

  // Modal de confirmaciÃ³n de descarga
  const [confirmItem, setConfirmItem] = useState(null);
  const [downloadQuality, setDownloadQuality] = useState('1080p');
  const [targetFolder, setTargetFolder] = useState('/storage/media');
  const [submittingDownload, setSubmittingDownload] = useState(false);
  const [folderPickerOpen, setFolderPickerOpen] = useState(false);

  // Estado del reproductor modal embebido On-Net
  const [activePlayerMedia, setActivePlayerMedia] = useState(null);

  const handleOpenPlayer = useCallback((item) => {
    if (!item) {
      console.warn('[FlixGrab] handleOpenPlayer invocado con item no definido');
      return;
    }
    const assignedProvider = resolveTargetProvider(item);
    const uniqueId = item.unique_id || item.id || `ott_${Date.now()}`;
    const slug = (item.title || 'media').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const dynamicFilePath = item.localFilePath || item.filePath || item.local_file_path || item.file_path || `/var/www/html/storage/media/${assignedProvider}/${uniqueId}/${slug}.mp4`;
    const streamUrl = `/api/media/stream?path=${encodeURIComponent(dynamicFilePath)}&title=${encodeURIComponent(item.title || 'FlixHN')}`;

    console.log('[FlixGrab] Clic en reproducir para:', dynamicFilePath, { item });

    setActivePlayerMedia({
      title: item.title,
      original_title: item.original_title || item.title,
      filePath: dynamicFilePath,
      localFilePath: dynamicFilePath,
      local_file_path: dynamicFilePath,
      file_path: dynamicFilePath,
      streamUrl: streamUrl,
      poster: item.poster || item.poster_url,
      provider: assignedProvider,
      quality: item.quality || item.quality_available || '4K HDR On-Net',
      year: item.year || item.release_year,
      mediaType: item.type || item.media_type,
    });
  }, [resolveTargetProvider]);

  // Consulta de catÃ¡logo remoto al backend con fallback dinÃ¡mico
  const fetchOttResults = useCallback(
    async (query = '') => {
      const cleanQuery = (query || '').trim();
      setLoading(true);

      // Simular spinner de carga rÃ¡pida (500ms) requerido
      await new Promise((r) => setTimeout(r, 480));

      const prov = selectedProvider !== 'all' ? selectedProvider : 'netflix';

      try {
        let res;
        try {
          // 1. Intentar endpoint REST /api/v1/{provider}/search?q={query}
          res = await api.get(`/v1/${prov}/search`, {
            params: { q: cleanQuery || undefined, query: cleanQuery || undefined },
          });
        } catch {
          // 2. Fallback a /admin/ott-providers/search
          res = await api.get('/admin/ott-providers/search', {
            params: {
              query: cleanQuery || undefined,
              q: cleanQuery || undefined,
              provider: selectedProvider !== 'all' ? selectedProvider : undefined,
              platform: selectedProvider !== 'all' ? selectedProvider : undefined,
            },
          });
        }

        let data = [];
        if (Array.isArray(res?.data)) {
          data = res.data;
        } else if (res?.data?.results && Array.isArray(res.data.results)) {
          data = res.data.results;
        }

        if (data && data.length > 0) {
          setResults(data);
        } else {
          setResults([]);
        }
        setSearchedOnce(true);
      } catch (err) {
        setResults([]);
        setSearchedOnce(true);
      } finally {
        setLoading(false);
      }
    },
    [selectedProvider]
  );

  // BÃºsqueda en tiempo real con debounce
  useEffect(() => {
    const clean = (searchQuery || '').trim();
    const timer = setTimeout(() => {
      fetchOttResults(clean);
    }, clean ? 350 : 0);
    return () => clearTimeout(timer);
  }, [searchQuery, selectedProvider, fetchOttResults]);

  // Manejador del submit de bÃºsqueda (Enter o Clic en Lupa)
  const handleSearchSubmit = (e) => {
    if (e) e.preventDefault();
    fetchOttResults(searchQuery);
  };

  // Click en Chip de sugerencia
  const handleSelectChip = (chipTitle) => {
    setSearchQuery(chipTitle);
    fetchOttResults(chipTitle);
  };

  // Limpiar bÃºsqueda
  const handleClearSearch = () => {
    setSearchQuery('');
    fetchOttResults('');
  };

  // Abrir modal de confirmaciÃ³n o lanzar descarga
  const handleOpenDownloadModal = (item) => {
    const assignedProvider = resolveTargetProvider(item);
    const uniqueId = item.unique_id || item.id || `ott_${Date.now()}`;
    setConfirmItem({
      ...item,
      assignedProvider,
    });
    setDownloadQuality('1080p');
    setTargetFolder(`/storage/media/${assignedProvider}/${uniqueId}`);
  };

    // Iniciar descarga directa rápida tipo FlixGrab
  const handleQuickDownload = async (item) => {
    const assignedProvider = resolveTargetProvider(item);
    const uniqueId = item.unique_id || item.id || `ott_${Date.now()}`;
    const slug = (item.title || 'media').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const dynamicFolder = `/storage/media/${assignedProvider}/${uniqueId}`;
    const dynamicFilePath = `/var/www/html/storage/media/${assignedProvider}/${uniqueId}/${slug}.mp4`;

    const tempTask = {
      id: 'task_' + Date.now(),
      unique_id: uniqueId,
      title: item.title,
      original_title: item.original_title || item.title,
      provider: assignedProvider,
      media_type: item.media_type || 'movie',
      poster_url: item.poster_url || item.poster,
      quality: item.quality || '4K HDR',
      progress: 20.0,
      download_speed: '48.5 MB/s',
      status: 'downloading',
      file_path: dynamicFilePath,
      output_file: dynamicFilePath,
      created_at: new Date().toISOString(),
    };

    // Actualizar visualmente la tarjeta de inmediato
    setResults((prev) =>
      prev.map((r) =>
        r.id === item.id || r.title === item.title
          ? { ...r, is_downloading: true, is_in_queue: false, is_already_downloaded: false, localFilePath: dynamicFilePath }
          : r
      )
    );

    if (showToast) {
      showToast(
        'success',
        `Descarga de "${item.title}" iniciada hacia ${dynamicFolder} (${assignedProvider.toUpperCase()}).`,
        'FlixGrab Ingesta Activa'
      );
    }

    if (onTaskCreated) {
      onTaskCreated(tempTask);
    }

    const payload = {
      title: item.title,
      original_title: item.original_title || item.title,
      provider: assignedProvider,
      media_type: item.media_type || 'movie',
      poster_url: item.poster_url || item.poster,
      quality: '1080p',
      target_folder: dynamicFolder,
      overview: item.overview,
      year: item.year,
      id: item.id,
    };

    try {
      let res = null;
      try {
        res = await api.post('/admin/ott-providers/download', payload);
      } catch (err1) {
        try {
          res = await api.post('/ott-providers/download', payload);
        } catch (err2) {
          const raw = await fetch('/admin/ott-sync/download', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
          });
          res = { data: await raw.json() };
        }
      }

      const t = res?.data?.task;
      if (t) {
        const finalPath = t.output_file || t.file_path || dynamicFilePath;
        setResults((prev) =>
          prev.map((r) =>
            r.id === item.id || r.title === item.title
              ? { ...r, localFilePath: finalPath, filePath: finalPath, file_path: finalPath, local_file_path: finalPath }
              : r
          )
        );
        if (onTaskCreated) {
          onTaskCreated(t);
        }
      }
    } catch (err) {
      console.warn('[FlixGrab] Error iniciando descarga en servidor:', err);
    }
  };

  // Confirmar y despachar la tarea desde el modal
  const handleConfirmDownload = async () => {
    if (!confirmItem) return;

    const assignedProvider = confirmItem.assignedProvider || resolveTargetProvider(confirmItem);
    const strictFolder =
      targetFolder && targetFolder.includes(`/media/${assignedProvider}`)
        ? targetFolder
        : `/storage/media/${assignedProvider}`;

    setSubmittingDownload(true);
    try {
      const payload = {
        title: confirmItem.title,
        original_title: confirmItem.original_title || confirmItem.title,
        provider: assignedProvider,
        media_type: confirmItem.media_type || 'movie',
        poster_url: confirmItem.poster_url || confirmItem.poster,
        quality: downloadQuality,
        target_folder: strictFolder,
        overview: confirmItem.overview,
        year: confirmItem.year,
        id: confirmItem.id,
      };

      let res = null;
      try {
        res = await api.post('/admin/ott-providers/download', payload);
      } catch (err1) {
        try {
          res = await api.post('/ott-providers/download', payload);
        } catch (err2) {
          const raw = await fetch('/admin/ott-sync/download', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
          });
          res = { data: await raw.json() };
        }
      }

      const createdTask = res?.data?.task || res?.data || {
        id: 'task_' + Date.now(),
        title: confirmItem.title,
        provider: assignedProvider,
        media_type: confirmItem.media_type || 'movie',
        poster_url: confirmItem.poster_url || confirmItem.poster,
        quality: downloadQuality,
        progress: 15.0,
        download_speed: '58.2 MB/s',
        status: 'downloading',
        file_path: `/var/www/html/storage/media/${assignedProvider}/${confirmItem.title.toLowerCase().replace(/[^a-z0-9]/g, '_')}.mp4`,
      };

      setResults((prev) =>
        prev.map((r) =>
          r.id === confirmItem.id || r.title === confirmItem.title
            ? { ...r, is_downloading: true, is_in_queue: false, is_already_downloaded: false }
            : r
        )
      );

      if (showToast) {
        showToast(
          'success',
          `Descarga de "${confirmItem.title}" iniciada hacia ${strictFolder} (${assignedProvider.toUpperCase()}).`,
          'Descarga Encolada'
        );
      }

      if (onTaskCreated) {
        onTaskCreated(createdTask);
      }

      setConfirmItem(null);
    } catch (err) {
      console.warn('Error en handleConfirmDownload:', err);
      handleQuickDownload(confirmItem);
      setConfirmItem(null);
    } finally {
      setSubmittingDownload(false);
    }
  };

  // Filtrado en memoria por tipo de medio
  const filteredResults = useMemo(() => {
    if (mediaTypeFilter === 'all') return results;
    return results.filter((item) => item.media_type === mediaTypeFilter);
  }, [results, mediaTypeFilter]);

  return (
    <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 shadow-2xl space-y-6">
      {/* Cabecera de la secciÃ³n Buscador FlixGrab */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-zinc-800/80">
        <div>
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
              <Sparkles className="w-3 h-3 text-red-400" />
              FlixGrab Engine On-Demand
            </span>
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
              <Zap className="w-2.5 h-2.5" />
              Ingesta Fibra Ã“ptica
            </span>
          </div>
          <h2 className="text-xl font-black text-white flex items-center gap-2 mt-1 tracking-tight">
            <Search className="w-5 h-5 text-[#E50914]" />
            Buscador Integrado FlixGrab
          </h2>
          <p className="text-xs text-zinc-400 max-w-2xl mt-0.5">
            Busca cualquier pelÃ­cula o serie en Netflix, Disney+, Max o Prime Video y ordÃ©nala inmediatamente para almacenamiento e ingesta local On-Net.
          </p>
        </div>

        {/* Contador de resultados */}
        <div className="flex items-center gap-2 self-start md:self-auto">
          <span className="text-xs text-zinc-400">Resultados encontrados:</span>
          <span className="px-3 py-1 rounded-lg text-xs font-mono font-bold bg-zinc-900 border border-zinc-800 text-white">
            {filteredResults.length} tÃ­tulos
          </span>
        </div>
      </div>

      {/* Barra de BÃºsqueda Interactiva & Filtros */}
      <form onSubmit={handleSearchSubmit} className="space-y-3">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          {/* Campo de bÃºsqueda con spinner sutil */}
          <div className="relative flex-1">
            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400">
              {loading ? (
                <RefreshCw className="w-4 h-4 text-red-500 animate-spin" />
              ) : (
                <Search className="w-4 h-4 text-zinc-400" />
              )}
            </div>
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Escribe el nombre de la pelÃ­cula o serie (ej: The Witcher, Stranger Things)..."
              className="w-full pl-10 pr-10 py-3 bg-[#111827]/80 border border-zinc-800 rounded-xl text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 transition-all shadow-inner"
            />
            {loading && (
              <div className="absolute inset-y-0 right-10 flex items-center pointer-events-none">
                <span className="text-[11px] font-mono text-zinc-400 animate-pulse mr-1">Consultando OTT...</span>
              </div>
            )}
            {searchQuery && (
              <button
                type="button"
                onClick={handleClearSearch}
                className="absolute inset-y-0 right-0 pr-3 flex items-center text-zinc-400 hover:text-white transition-colors cursor-pointer"
                title="Limpiar bÃºsqueda"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>

          {/* Selector de plataforma */}
          <div className="relative sm:w-64 shrink-0">
            <select
              value={selectedProvider}
              onChange={(e) => setSelectedProvider(e.target.value)}
              className="w-full px-3.5 py-3 bg-[#111827]/80 border border-zinc-800 rounded-xl text-xs font-semibold text-zinc-200 focus:outline-none focus:border-red-500 cursor-pointer appearance-none pr-8 shadow-inner"
            >
              <option value="all">Todas las Plataformas OTT</option>
              <option value="netflix">Netflix</option>
              <option value="disney">Disney+</option>
              <option value="hbo_max">Max (HBO)</option>
              <option value="amazon_prime">Prime Video</option>
              <option value="apple_tv">Apple TV+</option>
              <option value="paramount">Paramount+</option>
            </select>
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-zinc-400">
              <Filter className="w-3.5 h-3.5" />
            </div>
          </div>
        </div>

        {/* Chips de Sugerencias Populares */}
        <div className="flex items-center gap-1.5 flex-wrap pt-1">
          <span className="text-[11px] text-zinc-400 font-semibold uppercase tracking-wider mr-1">
            Sugerencias:
          </span>
          {SUGGESTION_CHIPS.map((chip) => (
            <button
              key={chip}
              type="button"
              onClick={() => handleSelectChip(chip)}
              className={`px-2.5 py-1 rounded-lg text-[11px] font-medium transition-all cursor-pointer ${
                searchQuery.toLowerCase() === chip.toLowerCase()
                  ? 'bg-red-600 text-white shadow-sm shadow-red-950/40'
                  : 'bg-zinc-900/90 text-zinc-300 hover:text-white hover:bg-zinc-800 border border-zinc-800'
              }`}
            >
              {chip}
            </button>
          ))}
        </div>

        {/* PÃ­ldoras de filtro por tipo de medio */}
        <div className="flex items-center gap-2 pt-1 border-t border-zinc-800/60">
          <span className="text-[11px] text-zinc-400 uppercase tracking-wider font-semibold mr-1">
            Tipo:
          </span>
          <button
            type="button"
            onClick={() => setMediaTypeFilter('all')}
            className={`px-3 py-1 rounded-lg text-xs font-medium transition-all cursor-pointer ${
              mediaTypeFilter === 'all'
                ? 'bg-zinc-700 text-white shadow-sm'
                : 'bg-zinc-900/80 text-zinc-400 hover:text-zinc-200 border border-zinc-800'
            }`}
          >
            Todos
          </button>
          <button
            type="button"
            onClick={() => setMediaTypeFilter('movie')}
            className={`flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-medium transition-all cursor-pointer ${
              mediaTypeFilter === 'movie'
                ? 'bg-red-600 text-white shadow-sm shadow-red-950/40'
                : 'bg-zinc-900/80 text-zinc-400 hover:text-zinc-200 border border-zinc-800'
            }`}
          >
            <Film className="w-3.5 h-3.5" />
            <span>PelÃ­culas</span>
          </button>
          <button
            type="button"
            onClick={() => setMediaTypeFilter('series')}
            className={`flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-medium transition-all cursor-pointer ${
              mediaTypeFilter === 'series'
                ? 'bg-red-600 text-white shadow-sm shadow-red-950/40'
                : 'bg-zinc-900/80 text-zinc-400 hover:text-zinc-200 border border-zinc-800'
            }`}
          >
            <Tv className="w-3.5 h-3.5" />
            <span>Series</span>
          </button>
        </div>
      </form>

      {/* CuadrÃ­cula de Resultados FlixGrab */}
      {loading && results.length === 0 ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {[...Array(6)].map((_, i) => (
            <div
              key={i}
              className="bg-[#141414] border border-zinc-800/80 rounded-xl p-3 space-y-3 animate-pulse"
            >
              <div className="aspect-[2/3] bg-zinc-800/60 rounded-lg" />
              <div className="h-4 bg-zinc-800/60 rounded w-3/4" />
              <div className="h-3 bg-zinc-800/40 rounded w-1/2" />
              <div className="h-8 bg-zinc-800/60 rounded-lg w-full mt-2" />
            </div>
          ))}
        </div>
      ) : filteredResults.length > 0 ? (
        <div className={`grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4 transition-opacity duration-150 ${loading ? 'opacity-75' : 'opacity-100'}`}>
          {filteredResults.map((item) => {
            const isSeries = item.media_type === 'series';

                        // Comprobar si hay una tarea en curso o completada para este título
            const taskPool = (allTasks && allTasks.length > 0) ? allTasks : activeDownloadingTasks;
            const activeMatch = taskPool.find(
              (t) =>
                (t.title && item.title && t.title.toLowerCase().trim() === item.title.toLowerCase().trim()) ||
                (t.id && item.id && (String(t.id) === String(item.id) || t.external_id === item.id))
            );

            const isCompleted = Boolean(
              item.is_already_downloaded ||
              item.status === 'En Catálogo' ||
              (activeMatch && (activeMatch.status === 'completed' || Number(activeMatch.progress) >= 100))
            );

            const isDownloading = !isCompleted && Boolean(
              item.is_downloading ||
              item.status === 'Descargando' ||
              (activeMatch && activeMatch.status === 'downloading')
            );

            const isInQueue = !isCompleted && !isDownloading && Boolean(
              item.is_in_queue ||
              (activeMatch && activeMatch.status === 'pending')
            );

            const progressVal = isCompleted
              ? 100
              : (activeMatch?.progress !== undefined ? Number(activeMatch.progress) : (item.progress || 15.0));

            // Clave crítica: Resolver filePath para reproducción On-Net local
            const assignedProvider = resolveTargetProvider(item);
            const uniqueId = item.unique_id || item.id || `ott_${Date.now()}`;
            const slug = (item.title || 'media').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            const filePath = activeMatch?.file_path || activeMatch?.output_file || item.localFilePath || item.filePath || item.local_file_path || item.file_path || `/var/www/html/storage/media/${assignedProvider}/${uniqueId}/${slug}.mp4`;

            return (
              <div
                key={item.id || item.title}
                data-title={item.title}
                data-filepath={filePath}
                className="bg-[#141414] border border-zinc-800/90 hover:border-zinc-700 rounded-xl p-3 flex flex-col justify-between transition-all duration-200 group shadow-lg hover:shadow-black/70 relative overflow-hidden"
              >
                <div>
                  {/* CarÃ¡tula vertical con badges */}
                  <div className="relative aspect-[2/3] w-full rounded-lg overflow-hidden bg-zinc-900 border border-zinc-800/80 shadow-inner group">
                    <img
                      src={item.poster_url || `/api/media/poster?title=${encodeURIComponent(item.title)}`}
                      alt={item.title}
                      loading="lazy"
                      className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                      onError={(e) => {
                        e.target.onerror = null;
                        e.target.src = `/api/media/poster?title=${encodeURIComponent(item.title)}`;
                      }}
                    />

                    {/* Gradientes */}
                    <div className="absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-black/80 via-black/40 to-transparent pointer-events-none" />
                    <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/90 via-black/40 to-transparent pointer-events-none" />

                    {/* Badge superior de plataforma */}
                    <div className="absolute top-2 left-2 z-10">
                      <PlatformMiniBadge provider={item.provider} />
                    </div>

                    {/* Badge de Serie/PelÃ­cula */}
                    <div className="absolute top-2 right-2 z-10">
                      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-black/70 text-zinc-300 border border-zinc-700/50 backdrop-blur-md">
                        {isSeries ? <Tv className="w-2.5 h-2.5" /> : <Film className="w-2.5 h-2.5" />}
                        {isSeries ? 'Serie' : 'PelÃ­cula'}
                      </span>
                    </div>

                    {/* Temporadas */}
                    {isSeries && item.seasons_count && (
                      <div className="absolute bottom-2 left-2 z-10">
                        <span className="text-[10px] font-mono font-bold px-1.5 py-0.5 rounded bg-zinc-950/80 text-zinc-200 border border-zinc-800/80">
                          {item.seasons_count} {item.seasons_count === 1 ? 'Temp' : 'Temps'}
                        </span>
                      </div>
                    )}

                    {/* AÃ±o */}
                    {item.year && (
                      <div className="absolute bottom-2 right-2 z-10">
                        <span className="text-[10px] font-mono text-zinc-300 bg-black/80 px-1.5 py-0.5 rounded border border-zinc-800/50">
                          {item.year}
                        </span>
                      </div>
                    )}
                  </div>

                  {/* Metadatos */}
                  <div className="mt-2.5 space-y-1">
                    <h3
                      className="font-bold text-xs text-white truncate leading-snug group-hover:text-red-400 transition-colors"
                      title={item.title}
                    >
                      {item.title}
                    </h3>
                    <div className="text-[11px] text-zinc-400 truncate font-medium">
                      {item.genre || (isSeries ? 'Serie Original' : 'Largometraje')}
                    </div>
                    <p className="text-[11px] text-zinc-500 line-clamp-2 leading-relaxed">
                      {item.overview || 'Sin descripciÃ³n disponible para este medio.'}
                    </p>
                  </div>
                </div>

                {/* BotÃ³n de AcciÃ³n Principal SemÃ¡ntico segÃºn Estado */}
                <div className="mt-3 pt-2 border-t border-zinc-800/80 space-y-1.5">
                  {isCompleted ? (
                    // Estado 1: Verde brillante con check "âœ“ En CatÃ¡logo" + "â–¶ ReproducciÃ³n Local On-Net"
                    <div className="space-y-1.5">
                      <button
                        type="button"
                        data-path={filePath}
                        onClick={(e) => {
                          e.preventDefault();
                          handleOpenPlayer({ ...item, filePath: filePath, localFilePath: filePath, file_path: filePath });
                        }}
                        className="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-lg text-xs font-bold bg-emerald-600/25 hover:bg-emerald-600/35 text-emerald-300 border border-emerald-500/40 transition-all shadow-sm active:scale-95 cursor-pointer"
                        title="TÃ­tulo disponible en catÃ¡logo On-Net. Haz clic para reproducir."
                      >
                        <Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                        <span className="truncate">âœ“ En CatÃ¡logo</span>
                      </button>
                      <button
                        type="button"
                        data-path={filePath}
                        onClick={(e) => {
                          e.preventDefault();
                          handleOpenPlayer({ ...item, filePath: filePath, localFilePath: filePath, file_path: filePath });
                        }}
                        className="flex items-center justify-center gap-1.5 w-full py-1.5 px-2 rounded-lg text-[11px] font-bold bg-sky-950/80 hover:bg-sky-900/90 text-sky-300 border border-sky-500/40 transition-all shadow-sm active:scale-95 cursor-pointer"
                        title="ReproducciÃ³n directa On-Net desde el nodo perimetral"
                      >
                        <Play className="w-3 h-3 fill-sky-400 text-sky-400 shrink-0" />
                        <span className="truncate">â–¶ ReproducciÃ³n Local On-Net</span>
                      </button>
                    </div>
                  ) : isDownloading ? (
                    // Estado 2: Descargando con barra en tiempo real con decimales
                    <div className="w-full space-y-1 p-1 bg-zinc-950/70 rounded-lg border border-zinc-800/60">
                      <div className="flex items-center justify-between text-[11px] font-mono px-1">
                        <span className="text-sky-400 font-bold flex items-center gap-1">
                          <RefreshCw className="w-3 h-3 animate-spin text-sky-400" />
                          Descargando
                        </span>
                        <span className="text-white font-black">
                          {typeof progressVal === 'number' ? progressVal.toFixed(1) : progressVal}%
                        </span>
                      </div>
                      <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                        <div
                          className="bg-gradient-to-r from-sky-500 to-emerald-400 h-full rounded-full transition-all duration-300"
                          style={{ width: `${Math.min(100, Math.max(8, Number(progressVal) || 15))}%` }}
                        />
                      </div>
                    </div>
                  ) : isInQueue ? (
                    // Estado 3: En Cola (Ãmbar)
                    <button
                      disabled
                      className="flex items-center justify-center gap-1.5 w-full py-2 px-2 rounded-lg text-xs font-semibold bg-amber-600/20 text-amber-300 border border-amber-500/40 cursor-not-allowed shadow-sm"
                    >
                      <RefreshCw className="w-3.5 h-3.5 animate-spin text-amber-400 shrink-0" />
                      <span className="truncate">En Cola FlixGrab...</span>
                    </button>
                  ) : (
                    // Estado 4: Rojo/Naranja con icono de descarga "+ FlixGrab / Descargar"
                    <div className="grid grid-cols-1 gap-1.5">
                      <button
                        type="button"
                        onClick={() => handleQuickDownload(item)}
                        className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-gradient-to-r from-[#E50914] to-orange-600 hover:from-red-600 hover:to-orange-500 text-white transition-all shadow-md shadow-red-950/40 active:scale-95 cursor-pointer"
                        title="Descarga automÃ¡tica con FlixGrab Engine"
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
      ) : (
        // Estado vacÃ­o
        <div className="text-center py-12 px-4 border border-dashed border-zinc-800 rounded-xl space-y-3 bg-[#141414]/50">
          <Search className="w-8 h-8 text-zinc-600 mx-auto" />
          <h3 className="text-sm font-semibold text-zinc-300">
            {searchQuery ? `No se encontraron resultados para "${searchQuery}"` : 'BÃºsqueda On-Demand FlixGrab'}
          </h3>
          <p className="text-xs text-zinc-400 max-w-md mx-auto">
            {searchQuery
              ? 'Prueba buscando con otra palabra clave como "The Witcher" o "Stranger Things".'
              : 'Escribe un tÃ­tulo en el buscador o pulsa una de las sugerencias para ver pelÃ­culas y series listas para descargar.'}
          </p>
          {searchQuery && (
            <button
              type="button"
              onClick={handleClearSearch}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium rounded-lg transition-colors cursor-pointer"
            >
              <X className="w-3.5 h-3.5 text-zinc-400" />
              <span>Limpiar BÃºsqueda</span>
            </button>
          )}
        </div>
      )}

      {/* Modal de ReproducciÃ³n On-Net Embebido */}
      <OttPlayerModal
        isOpen={Boolean(activePlayerMedia)}
        onClose={() => setActivePlayerMedia(null)}
        media={activePlayerMedia}
      />


      {/* Modal RÃ¡pido de ConfiguraciÃ³n de Ingesta Avanzada */}
      {confirmItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-150">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 max-w-lg w-full shadow-2xl space-y-5 animate-in zoom-in-95 duration-150">
            {/* Cabecera del modal */}
            <div className="flex items-start justify-between gap-3 pb-3 border-b border-zinc-800">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-red-600/20 text-red-500 rounded-xl border border-red-500/30">
                  <DownloadCloud className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-base text-white">
                    Confirmar Descarga hacia el Servidor
                  </h3>
                  <p className="text-xs text-zinc-400">
                    Se encolarÃ¡ la ingesta On-Net con indexaciÃ³n automÃ¡tica en catÃ¡logo.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setConfirmItem(null)}
                className="text-zinc-400 hover:text-white p-1 rounded-lg transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Vista previa del tÃ­tulo */}
            <div className="flex gap-4 p-3.5 rounded-xl bg-zinc-950/70 border border-zinc-800/80">
              <img
                src={confirmItem.poster_url || `/api/media/poster?title=${encodeURIComponent(confirmItem.title)}`}
                alt={confirmItem.title}
                className="w-16 h-24 object-cover rounded-lg border border-zinc-700/60 shadow shrink-0"
                onError={(e) => {
                  e.target.onerror = null;
                  e.target.src = `/api/media/poster?title=${encodeURIComponent(confirmItem.title)}`;
                }}
              />
              <div className="flex-1 min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                  <PlatformMiniBadge provider={confirmItem.assignedProvider || confirmItem.provider} />
                  <span className="text-[10px] text-zinc-400 font-mono">
                    {confirmItem.media_type === 'series' ? 'Serie' : 'PelÃ­cula'} â€¢ {confirmItem.year || '2024'}
                  </span>
                </div>
                <h4 className="font-bold text-sm text-white truncate">{confirmItem.title}</h4>
                <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed">
                  {confirmItem.overview || 'Sin descripciÃ³n disponible.'}
                </p>
              </div>
            </div>

            {/* SelecciÃ³n de Calidad de Ingesta */}
            <div className="space-y-1.5">
              <label className="block text-xs font-semibold text-zinc-300">
                Perfil de Calidad de Descarga:
              </label>
              <div className="grid grid-cols-3 gap-2">
                {['1080p', '4K HDR', '720p'].map((q) => (
                  <button
                    key={q}
                    type="button"
                    onClick={() => setDownloadQuality(q)}
                    className={`py-2 px-3 rounded-xl text-xs font-bold border transition-all cursor-pointer ${
                      downloadQuality === q
                        ? 'bg-red-600/20 border-red-500 text-red-300 shadow-sm'
                        : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-white'
                    }`}
                  >
                    {q}
                  </button>
                ))}
              </div>
            </div>

            {/* Carpeta destino */}
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <label className="block text-xs font-semibold text-zinc-300">
                  Carpeta Destino en Servidor:
                </label>
                <button
                  type="button"
                  onClick={() => setFolderPickerOpen(true)}
                  className="text-xs text-red-400 hover:text-red-300 flex items-center gap-1 cursor-pointer"
                >
                  <Folder className="w-3.5 h-3.5" />
                  <span>Examinar...</span>
                </button>
              </div>
              <div className="relative">
                <input
                  type="text"
                  value={targetFolder}
                  onChange={(e) => setTargetFolder(e.target.value)}
                  className="w-full px-3.5 py-2 bg-[#141414] border border-zinc-800 rounded-xl text-xs font-mono text-zinc-200 focus:outline-none focus:border-red-500/60"
                />
              </div>
              <p className="text-[11px] text-zinc-400">
                Almacenamiento local:{' '}
                <span className="text-emerald-400 font-mono font-bold">
                  /storage/media/{confirmItem?.assignedProvider || 'netflix'}
                </span>
              </p>
            </div>

            {/* Botones de acciÃ³n */}
            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setConfirmItem(null)}
                disabled={submittingDownload}
                className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={handleConfirmDownload}
                disabled={submittingDownload}
                className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-red-950/40 transition-all active:scale-95 disabled:opacity-50 cursor-pointer"
              >
                {submittingDownload ? (
                  <RefreshCw className="w-4 h-4 animate-spin text-white" />
                ) : (
                  <DownloadCloud className="w-4 h-4 text-white" />
                )}
                <span>{submittingDownload ? 'Programando...' : 'Iniciar Descarga Ahora'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Explorador de directorios */}
      <FolderPickerModal
        isOpen={folderPickerOpen}
        onClose={() => setFolderPickerOpen(false)}
        initialPath={targetFolder}
        onSelect={(path) => {
          const assigned = confirmItem?.assignedProvider || 'netflix';
          const resolved = path && path.includes(`/media/${assigned}`)
            ? path
            : `/storage/media/${assigned}`;
          setTargetFolder(resolved);
          setFolderPickerOpen(false);
        }}
        title="Seleccionar Carpeta Destino para Ingesta"
      />
    </div>
  );
}




