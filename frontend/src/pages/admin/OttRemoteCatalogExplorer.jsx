import React, { useState, useEffect, useCallback, useMemo } from 'react';
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

  // Modal de confirmación rápida de descarga
  const [confirmItem, setConfirmItem] = useState(null);
  const [downloadQuality, setDownloadQuality] = useState('1080p');
  const [targetFolder, setTargetFolder] = useState('/storage/media');
  const [submittingDownload, setSubmittingDownload] = useState(false);

  // Explorador de carpetas dentro del modal de descarga
  const [folderPickerOpen, setFolderPickerOpen] = useState(false);

  // Consulta de catálogo remoto al backend
  const fetchRemoteCatalog = useCallback(
    async (query = '', provider = 'all') => {
      setLoading(true);
      try {
        const res = await api.get('/admin/ott-providers/search', {
          params: {
            query: query.trim(),
            provider: provider !== 'all' ? provider : undefined,
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
    [showToast]
  );

  // Carga inicial de títulos destacados al montar el componente
  useEffect(() => {
    fetchRemoteCatalog('', 'all');
  }, [fetchRemoteCatalog]);

  // Manejador del submit de búsqueda
  const handleSearchSubmit = (e) => {
    if (e) e.preventDefault();
    fetchRemoteCatalog(searchQuery, selectedProvider);
  };

  // Cambio de plataforma desde el selector
  const handleProviderChange = (newProvider) => {
    setSelectedProvider(newProvider);
    fetchRemoteCatalog(searchQuery, newProvider);
  };

  // Limpiar búsqueda
  const handleClearSearch = () => {
    setSearchQuery('');
    fetchRemoteCatalog('', selectedProvider);
  };

  // Abrir modal de confirmación de descarga
  const handleOpenDownloadModal = (item) => {
    setConfirmItem(item);
    setDownloadQuality('1080p');

    // Asignar ruta de carpeta destino por defecto según el proveedor o tipo
    const provSlug = item.provider || 'movies';
    setTargetFolder(`/storage/media/${provSlug}`);
  };

  // Confirmar y despachar la tarea de descarga
  const handleConfirmDownload = async () => {
    if (!confirmItem) return;

    setSubmittingDownload(true);
    try {
      const payload = {
        title: confirmItem.title,
        original_title: confirmItem.original_title || confirmItem.title,
        provider: confirmItem.provider || 'netflix',
        media_type: confirmItem.media_type || 'movie',
        poster_url: confirmItem.poster_url,
        quality: downloadQuality,
        target_folder: targetFolder,
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
            `Descarga de "${confirmItem.title}" iniciada hacia ${targetFolder}.`,
            'Descarga Encolada'
          );
        }

        // Notificar al componente padre para refrescar la cola inferior
        if (onTaskCreated) {
          onTaskCreated(createdTask);
        }

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

      {/* Barra de Búsqueda Interactiva & Filtros */}
      <form onSubmit={handleSearchSubmit} className="space-y-3">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          {/* Campo de búsqueda de texto */}
          <div className="relative flex-1">
            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400">
              <Search className="w-4 h-4" />
            </div>
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Buscar película, serie, documental en tus plataformas conectadas..."
              className="w-full pl-10 pr-10 py-2.5 bg-[#141414] border border-zinc-800 rounded-xl text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-red-500/60 focus:ring-1 focus:ring-red-500/50 transition-all"
            />
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

          {/* Selector de plataforma */}
          <div className="relative sm:w-56 shrink-0">
            <select
              value={selectedProvider}
              onChange={(e) => handleProviderChange(e.target.value)}
              className="w-full px-3.5 py-2.5 bg-[#141414] border border-zinc-800 rounded-xl text-xs font-medium text-zinc-200 focus:outline-none focus:border-red-500/60 cursor-pointer appearance-none pr-8"
            >
              <option value="all">Todas las Plataformas</option>
              <option value="netflix">Netflix</option>
              <option value="disney">Disney+</option>
              <option value="hbo_max">Max (HBO)</option>
              <option value="amazon_prime">Amazon Prime Video</option>
              <option value="apple_tv">Apple TV+</option>
              <option value="paramount">Paramount+</option>
              {connectedProviders
                .filter(
                  (p) =>
                    !['netflix', 'disney', 'hbo_max', 'amazon_prime', 'apple_tv', 'paramount'].includes(
                      p.provider
                    )
                )
                .map((p) => (
                  <option key={p.provider} value={p.provider}>
                    {p.name || p.provider}
                  </option>
                ))}
            </select>
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-zinc-400">
              <Filter className="w-3.5 h-3.5" />
            </div>
          </div>

          {/* Botón de búsqueda */}
          <button
            type="submit"
            disabled={loading}
            className="flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-red-950/40 transition-all active:scale-95 disabled:opacity-50 cursor-pointer shrink-0"
          >
            {loading ? (
              <RefreshCw className="w-4 h-4 animate-spin text-white" />
            ) : (
              <Search className="w-4 h-4 text-white" />
            )}
            <span>{loading ? 'Consultando...' : 'Buscar en Catálogo'}</span>
          </button>
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
      {loading ? (
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
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
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
        // Estado vacío
        <div className="text-center py-12 px-4 border border-dashed border-zinc-800 rounded-xl space-y-3 bg-[#141414]/50">
          <Search className="w-8 h-8 text-zinc-600 mx-auto" />
          <h3 className="text-sm font-semibold text-zinc-300">
            {searchedOnce
              ? `No se encontraron resultados para "${searchQuery}"`
              : 'Explora o busca títulos en tus plataformas conectadas'}
          </h3>
          <p className="text-xs text-zinc-400 max-w-md mx-auto">
            {searchedOnce
              ? 'Prueba buscando con palabras clave en español o inglés, o selecciona otra plataforma en el filtro superior.'
              : 'Escribe el nombre de cualquier película o serie arriba para buscarla remotamente en Netflix, Disney+, Max, etc.'}
          </p>
          {searchedOnce && (
            <button
              type="button"
              onClick={handleClearSearch}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium rounded-lg transition-colors cursor-pointer"
            >
              <RefreshCw className="w-3.5 h-3.5 text-zinc-400" />
              <span>Ver Títulos Destacados</span>
            </button>
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
                  <PlatformMiniBadge provider={confirmItem.provider} />
                  <span className="text-[10px] text-zinc-400 font-mono">
                    {confirmItem.media_type === 'series' ? 'Serie' : 'Película'} • {confirmItem.year || '2024'}
                  </span>
                </div>
                <h4 className="font-bold text-sm text-white truncate">{confirmItem.title}</h4>
                <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed">
                  {confirmItem.overview || 'Sin descripción disponible.'}
                </p>
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
                La ruta física se validará y creará automáticamente con permisos completos (0777).
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
          setTargetFolder(path);
          setFolderPickerOpen(false);
        }}
        title="Seleccionar Carpeta Destino para Ingesta"
      />
    </div>
  );
}
