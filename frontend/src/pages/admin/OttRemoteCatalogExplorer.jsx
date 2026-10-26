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
} from 'lucide-react';
import FolderPickerModal from './FolderPickerModal';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';

/**
 * Insignia vectorial o estilizada de la plataforma para las tarjetas de catálogo remoto.
 */
const PlatformMiniBadge = ({ provider }) => {
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
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-purple-950/70 text-purple-300 border border-purple-500/30 shadow-sm backdrop-blur-md">
          <span className="w-1.5 h-1.5 rounded-full bg-purple-400" />
          {provider || 'OTT'}
        </span>
      );
  }
};

/**
 * Explorador y Buscador de Catálogo Remoto On-Demand
 * Permite buscar títulos por nombre dentro de plataformas OTT conectadas y ordenar su descarga física.
 */
export default function OttRemoteCatalogExplorer({
  onTaskCreated,
  showToast,
  connectedProviders = [],
}) {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedProvider, setSelectedProvider] = useState('all');
  const [mediaTypeFilter, setMediaTypeFilter] = useState('all'); // 'all' | 'movie' | 'series'
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [searchedOnce, setSearchedOnce] = useState(false);

  // Lista sincronizada de proveedores y filtro estricto de plataformas activas en estado 'connected'
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

  // Resolver qué proveedor conectado debe asumir la descarga
  const resolveTargetProvider = useCallback(
    (item) => {
      if (activeConnectedProviders.length === 0) {
        return null;
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

  // Modal de confirmación rápida de descarga
  const [confirmItem, setConfirmItem] = useState(null);
  const [downloadQuality, setDownloadQuality] = useState('1080p');
  const [targetFolder, setTargetFolder] = useState('/storage/media');
  const [submittingDownload, setSubmittingDownload] = useState(false);

  // Explorador de carpetas dentro del modal de descarga
  const [folderPickerOpen, setFolderPickerOpen] = useState(false);

  // Consulta de catálogo remoto al backend (Tiempo real y carga de catálogo conectado)
  const fetchOttResults = useCallback(
    async (query = '') => {
      const cleanQuery = (query || '').trim();
      if (cleanQuery.length === 1) {
        return;
      }

      setLoading(true);
      try {
        const res = await api.get('/admin/ott-providers/search', {
          params: {
            query: cleanQuery || undefined,
            provider: selectedProvider !== 'all' ? selectedProvider : undefined,
          },
        });

        let data = [];
        if (Array.isArray(res.data)) {
          data = res.data;
        } else if (res.data?.results && Array.isArray(res.data.results)) {
          data = res.data.results;
        }

        setResults(data);
        setSearchedOnce(true);
      } catch (err) {
        console.error('Error al consultar catálogo remoto OTT:', err);
        if (showToast) {
          showToast('error', 'No fue posible consultar el catálogo remoto.');
        }
      } finally {
        setLoading(false);
      }
    },
    [selectedProvider, showToast]
  );

  // Carga inicial y búsqueda en tiempo real con debounce
  useEffect(() => {
    const clean = (searchQuery || '').trim();
    if (clean.length === 1) {
      return;
    }
    const timer = setTimeout(() => {
      fetchOttResults(clean);
    }, clean ? 350 : 0);
    return () => clearTimeout(timer);
  }, [searchQuery, selectedProvider, fetchOttResults]);

  // Manejador del submit de búsqueda (Enter)
  const handleSearchSubmit = (e) => {
    if (e) e.preventDefault();
    const clean = (searchQuery || '').trim();
    if (clean.length !== 1) {
      fetchOttResults(clean);
    }
  };

  // Cambio de plataforma desde el selector
  const handleProviderChange = (newProvider) => {
    setSelectedProvider(newProvider);
  };

  // Limpiar búsqueda y restaurar catálogo
  const handleClearSearch = () => {
    setSearchQuery('');
    fetchOttResults('');
  };

  // Abrir modal de confirmación de descarga verificando plataformas conectadas
  const handleOpenDownloadModal = (item) => {
    if (activeConnectedProviders.length === 0) {
      if (showToast) {
        showToast(
          'warning',
          'No tienes plataformas OTT conectadas. Conecta una plataforma (como Netflix) antes de ordenar descargas.',
          'Sin Plataforma Conectada'
        );
      }
      return;
    }

    const assignedProvider = resolveTargetProvider(item);
    setConfirmItem({
      ...item,
      assignedProvider,
    });
    setDownloadQuality('1080p');

    // La carpeta destino DEBE ser estrictamente /storage/media/{assignedProvider}
    setTargetFolder(`/storage/media/${assignedProvider}`);
  };

  // Confirmar y despachar la tarea de descarga
  const handleConfirmDownload = async () => {
    if (!confirmItem) return;

    if (activeConnectedProviders.length === 0) {
      if (showToast) {
        showToast(
          'warning',
          'No tienes plataformas OTT conectadas. Conecta una plataforma activa antes de descargar.',
          'Plataforma Requerida'
        );
      }
      return;
    }

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
        poster_url: confirmItem.poster_url,
        quality: downloadQuality,
        target_folder: strictFolder,
        overview: confirmItem.overview,
        year: confirmItem.year,
        id: confirmItem.id,
      };

      const res = await api.post('/admin/ott-providers/download', payload);

      if (res.status === 201 || res.data?.success) {
        const createdTask = res.data?.task || res.data;

        // Actualizar el estado local en la cuadrícula para reflejar "En Cola" al instante
        setResults((prev) =>
          prev.map((r) =>
            r.id === confirmItem.id || r.title === confirmItem.title
              ? { ...r, is_in_queue: true, is_already_downloaded: false }
              : r
          )
        );

        if (showToast) {
          showToast(
            'success',
            `Descarga de "${confirmItem.title}" iniciada hacia ${strictFolder} (${assignedProvider}).`,
            'Descarga Encolada'
          );
        }

        // Notificar al componente padre para refrescar la cola inferior
        if (onTaskCreated) {
          onTaskCreated(createdTask);
        }

        // Emitir señal global al canal para refrescar el catálogo en /browse y otras pestañas
        notifyCatalogUpdated({
          action: 'task_created',
          task: createdTask,
          title: confirmItem.title,
          provider: assignedProvider,
        });

        setConfirmItem(null);
      }
    } catch (err) {
      console.error('Error al programar descarga:', err);
      const msg = err.response?.data?.message || 'Error al iniciar la descarga hacia el servidor.';
      if (showToast) {
        showToast('error', msg, 'Error de Ingesta');
      }
    } finally {
      setSubmittingDownload(false);
    }
  };

  // Filtrado reactivo en memoria por tipo de medio (Película vs Serie)
  const filteredResults = useMemo(() => {
    if (mediaTypeFilter === 'all') return results;
    return results.filter((item) => item.media_type === mediaTypeFilter);
  }, [results, mediaTypeFilter]);

  return (
    <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 shadow-xl space-y-6">
      {/* Cabecera de la sección */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-zinc-800/80">
        <div>
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
              <Sparkles className="w-3 h-3 text-red-400" />
              On-Demand Remote Fetch
            </span>
          </div>
          <h2 className="text-lg md:text-xl font-bold text-white flex items-center gap-2 mt-1">
            <Search className="w-5 h-5 text-[#E50914]" />
            Explorador y Búsqueda de Catálogo Remoto
          </h2>
          <p className="text-xs text-zinc-400 max-w-2xl mt-0.5">
            Explora títulos directamente desde los servidores de Netflix, Disney+, Max, Prime Video y TMDb.
            Ordena la descarga física hacia tu almacenamiento On-Net con un solo clic.
          </p>
        </div>

        {/* Contador de resultados */}
        <div className="flex items-center gap-2 self-start md:self-auto">
          <span className="text-xs text-zinc-400">Resultados disponibles:</span>
          <span className="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-zinc-900 border border-zinc-800 text-white">
            {filteredResults.length} títulos
          </span>
        </div>
      </div>

      {/* Alerta si no hay plataformas conectadas */}
      {activeConnectedProviders.length === 0 && (
        <div className="flex items-center gap-3 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs">
          <AlertCircle className="w-5 h-5 text-amber-400 shrink-0" />
          <div className="flex-1">
            <span className="font-bold">No hay plataformas OTT conectadas:</span>
            <span className="ml-1 text-zinc-300">
              Para ordenar descargas directas a tu almacenamiento On-Net, conecta una plataforma activa (ej. Netflix) en el panel de cuentas superior.
            </span>
          </div>
        </div>
      )}

      {/* Barra de Búsqueda Interactiva & Filtros */}
      <form onSubmit={handleSearchSubmit} className="space-y-3">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          {/* Campo de búsqueda de texto con spinner sutil integrado */}
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
              placeholder="Escribe para buscar en tiempo real (mín. 2 letras)..."
              className="w-full pl-10 pr-10 py-2.5 bg-[#141414] border border-zinc-800 rounded-xl text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-red-500/60 focus:ring-1 focus:ring-red-500/50 transition-all"
            />
            {loading && (
              <div className="absolute inset-y-0 right-10 flex items-center pointer-events-none">
                <span className="text-[11px] font-mono text-zinc-500 animate-pulse mr-1">Buscando...</span>
              </div>
            )}
            {searchQuery && (
              <button
                type="button"
                onClick={handleClearSearch}
                className="absolute inset-y-0 right-0 pr-3 flex items-center text-zinc-400 hover:text-white transition-colors cursor-pointer"
                title="Limpiar búsqueda"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>

          {/* Selector de plataforma filtrado por conectadas */}
          <div className="relative sm:w-64 shrink-0">
            <select
              value={selectedProvider}
              onChange={(e) => handleProviderChange(e.target.value)}
              className="w-full px-3.5 py-2.5 bg-[#141414] border border-zinc-800 rounded-xl text-xs font-medium text-zinc-200 focus:outline-none focus:border-red-500/60 cursor-pointer appearance-none pr-8"
            >
              <option value="all">
                {activeConnectedProviders.length > 0
                  ? `Todas las Conectadas (${activeConnectedProviders.length})`
                  : 'Todas las Plataformas'}
              </option>
              {activeConnectedProviders.map((p) => (
                <option key={p.provider} value={p.provider}>
                  {p.name || p.provider} (Conectada)
                </option>
              ))}
              {providersList
                .filter(
                  (p) => !activeConnectedProviders.some((cp) => cp.provider === p.provider)
                )
                .map((p) => (
                  <option key={p.provider} value={p.provider} disabled className="text-zinc-600">
                    {p.name || p.provider} (Desconectada)
                  </option>
                ))}
            </select>
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-zinc-400">
              <Filter className="w-3.5 h-3.5" />
            </div>
          </div>
        </div>

        {/* Píldoras de filtro por tipo de medio */}
        <div className="flex items-center gap-2 pt-1">
          <span className="text-[11px] text-zinc-400 uppercase tracking-wider font-semibold mr-1">
            Filtrar:
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
            <span>Películas</span>
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

      {/* Cuadrícula de Resultados */}
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
        <div className={`grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4 transition-opacity duration-150 ${loading ? 'opacity-70' : 'opacity-100'}`}>
          {filteredResults.map((item) => {
            const isSeries = item.media_type === 'series';

            return (
              <div
                key={item.id || item.title}
                className="bg-[#141414] border border-zinc-800/90 hover:border-zinc-700 rounded-xl p-3 flex flex-col justify-between transition-all duration-200 group shadow-lg hover:shadow-black/60 relative overflow-hidden"
              >
                <div>
                  {/* Carátula vertical con badges flotantes */}
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

                    {/* Gradiente superior e inferior para legibilidad de badges */}
                    <div className="absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-black/80 via-black/40 to-transparent pointer-events-none" />
                    <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/90 via-black/40 to-transparent pointer-events-none" />

                    {/* Badge superior de plataforma */}
                    <div className="absolute top-2 left-2 z-10">
                      <PlatformMiniBadge provider={item.provider} />
                    </div>

                    {/* Badge superior de tipo (Serie / Película) */}
                    <div className="absolute top-2 right-2 z-10">
                      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-black/70 text-zinc-300 border border-zinc-700/50 backdrop-blur-md">
                        {isSeries ? <Tv className="w-2.5 h-2.5" /> : <Film className="w-2.5 h-2.5" />}
                        {isSeries ? 'Serie' : 'Película'}
                      </span>
                    </div>

                    {/* Insignia inferior de temporadas si es serie */}
                    {isSeries && item.seasons_count && (
                      <div className="absolute bottom-2 left-2 z-10">
                        <span className="text-[10px] font-mono font-semibold px-1.5 py-0.5 rounded bg-zinc-950/80 text-zinc-300 border border-zinc-800/80">
                          {item.seasons_count} {item.seasons_count === 1 ? 'Temp' : 'Temps'}
                        </span>
                      </div>
                    )}

                    {/* Año de estreno */}
                    {item.year && (
                      <div className="absolute bottom-2 right-2 z-10">
                        <span className="text-[10px] font-mono text-zinc-400 bg-black/70 px-1.5 py-0.5 rounded">
                          {item.year}
                        </span>
                      </div>
                    )}
                  </div>

                  {/* Metadatos: Título y Sinopsis */}
                  <div className="mt-2.5 space-y-1">
                    <h3
                      className="font-bold text-xs text-white truncate leading-snug group-hover:text-red-400 transition-colors"
                      title={item.title}
                    >
                      {item.title}
                    </h3>
                    <p className="text-[11px] text-zinc-400 line-clamp-2 leading-relaxed">
                      {item.overview || 'Sin descripción disponible para este medio.'}
                    </p>
                  </div>
                </div>

                {/* Botón de Acción Principal */}
                <div className="mt-3 pt-2 border-t border-zinc-800/80">
                  {item.is_already_downloaded ? (
                    // Estado 1: Ya existe en FlixHN (Verde)
                    <a
                      href={item.title_id ? `/browse?title=${item.title_id}` : '/browse'}
                      className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 border border-emerald-500/30 transition-all shadow-sm"
                      title="Título presente en la biblioteca de FlixHN"
                    >
                      <Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                      <span className="truncate">En Catálogo</span>
                      <ExternalLink className="w-3 h-3 text-emerald-400/80 shrink-0 ml-0.5" />
                    </a>
                  ) : item.is_in_queue ? (
                    // Estado 2: Ya está en cola (Ámbar deshabilitado)
                    <button
                      disabled
                      className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-semibold bg-amber-600/20 text-amber-400 border border-amber-500/30 cursor-not-allowed shadow-sm"
                      title="Este título ya se encuentra en proceso de descarga en la cola"
                    >
                      <RefreshCw className="w-3.5 h-3.5 animate-spin text-amber-400 shrink-0" />
                      <span className="truncate">En Cola...</span>
                    </button>
                  ) : (
                    // Estado 3: No descargado (Botón Rojo Descargar)
                    <button
                      type="button"
                      onClick={() => handleOpenDownloadModal(item)}
                      className="flex items-center justify-center gap-1.5 w-full py-2 px-2.5 rounded-lg text-xs font-bold bg-[#E50914] hover:bg-red-700 text-white transition-all shadow-md shadow-red-950/30 active:scale-95 cursor-pointer"
                    >
                      <DownloadCloud className="w-3.5 h-3.5 text-white shrink-0" />
                      <span className="truncate">Descargar</span>
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        // Estado vacío o en consulta
        <div className="text-center py-12 px-4 border border-dashed border-zinc-800 rounded-xl space-y-3 bg-[#141414]/50">
          {loading ? (
            <>
              <RefreshCw className="w-8 h-8 text-red-500 animate-spin mx-auto" />
              <h3 className="text-sm font-semibold text-zinc-300">
                Consultando catálogo remoto OTT...
              </h3>
              <p className="text-xs text-zinc-500 max-w-md mx-auto">
                Buscando coincidencias para "{searchQuery}" en plataformas conectadas.
              </p>
            </>
          ) : !searchQuery || searchQuery.trim().length < 2 ? (
            <>
              <Search className="w-8 h-8 text-zinc-600 mx-auto" />
              <h3 className="text-sm font-semibold text-zinc-300">
                Búsqueda On-Demand en Tiempo Real
              </h3>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                Escribe al menos 2 caracteres en el buscador para consultar al instante títulos disponibles en Netflix, Disney+, Max, Prime Video y TMDb.
              </p>
            </>
          ) : (
            <>
              <AlertCircle className="w-8 h-8 text-zinc-600 mx-auto" />
              <h3 className="text-sm font-semibold text-zinc-300">
                No se encontraron resultados para "{searchQuery}"
              </h3>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                Prueba buscando con palabras clave diferentes o selecciona otra plataforma en el selector.
              </p>
              <button
                type="button"
                onClick={handleClearSearch}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium rounded-lg transition-colors cursor-pointer"
              >
                <X className="w-3.5 h-3.5 text-zinc-400" />
                <span>Limpiar Búsqueda</span>
              </button>
            </>
          )}
        </div>
      )}

      {/* Modal Rápido de Confirmación de Descarga */}
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
                    Se encolará la ingesta On-Net con indexación automática en catálogo.
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

            {/* Vista previa del título */}
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
                    {confirmItem.media_type === 'series' ? 'Serie' : 'Película'} • {confirmItem.year || '2024'}
                  </span>
                </div>
                <h4 className="font-bold text-sm text-white truncate">{confirmItem.title}</h4>
                <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed">
                  {confirmItem.overview || 'Sin descripción disponible.'}
                </p>
                {confirmItem.provider && confirmItem.assignedProvider && confirmItem.provider !== confirmItem.assignedProvider && (
                  <p className="text-[11px] text-amber-400/90 flex items-center gap-1.5 mt-1 font-medium bg-amber-500/10 px-2 py-1 rounded-lg border border-amber-500/20">
                    <AlertCircle className="w-3.5 h-3.5 shrink-0 text-amber-400" />
                    <span>
                      Asociado a tu plataforma conectada: <strong>{(confirmItem.assignedProvider).toUpperCase()}</strong>
                    </span>
                  </p>
                )}
              </div>
            </div>

            {/* Selección de Calidad de Ingesta */}
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

            {/* Carpeta destino en almacenamiento local */}
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
                Almacenamiento exclusivo para plataforma conectada:{' '}
                <span className="text-emerald-400 font-mono font-bold">
                  /storage/media/{confirmItem?.assignedProvider || 'netflix'}
                </span>
              </p>
            </div>

            {/* Botones de acción */}
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

      {/* Explorador interactivo de directorios (FolderPickerModal) */}
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
