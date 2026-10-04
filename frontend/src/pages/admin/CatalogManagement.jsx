import React, { useState } from 'react';
import api from '../../services/api';
import { Film, Plus, Trash2, X, RefreshCw } from 'lucide-react';

const CatalogManagement = ({ titles = [], onRefresh }) => {
  const [showAddTitle, setShowAddTitle] = useState(false);
  const [showAddEpisode, setShowAddEpisode] = useState(null); // title object
  const [loading, setLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);

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
      if (onRefresh) onRefresh();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al guardar título');
    } finally {
      setLoading(false);
    }
  };

  const handleDeleteTitle = async (id) => {
    if (!window.confirm('¿Seguro que deseas eliminar este título del catálogo?')) return;
    try {
      await api.delete(`/admin/titles/${id}`);
      if (onRefresh) onRefresh();
    } catch (err) {
      alert('Error eliminando título');
    }
  };

  const handleAddEpisodeSubmit = async (e) => {
    e.preventDefault();
    try {
      await api.post(`/admin/titles/${showAddEpisode.id}/episodes`, newEpisode);
      alert('Episodio agregado con éxito.');
      setShowAddEpisode(null);
      if (onRefresh) onRefresh();
    } catch (err) {
      alert(err.response?.data?.message || 'Error agregando episodio');
    }
  };

  const [mediaServerUrl, setMediaServerUrl] = useState('http://45.4.87.126:6789');
  const [mediaApiKey, setMediaApiKey] = useState('');
  const [syncingRemote, setSyncingRemote] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);

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
            {(titles || []).length > 0 ? (
              (titles || []).map((t) => (
                <tr key={t.id} className="hover:bg-gray-50 dark:hover:bg-zinc-800/40 transition-colors">
                  <td className="p-3.5 flex items-center gap-3">
                    <img
                      src={t.poster_url}
                      alt={t.name}
                      className="w-9 h-12 object-cover rounded bg-gray-100 dark:bg-zinc-800"
                      onError={(e) => {
                        e.target.onerror = null;
                        e.target.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 36 48"><rect width="100%" height="100%" fill="%23222"/></svg>';
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
                  <td className="p-3.5 text-right space-x-2">
                    {t.type === 'series' && (
                      <button
                        onClick={() => {
                          setShowAddEpisode(t);
                          setNewEpisode((prev) => ({
                            ...prev,
                            season_id: t.seasons?.[0]?.id || '',
                          }));
                        }}
                        className="text-purple-700 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-semibold text-xs px-2 py-1 rounded bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800/60 transition-colors"
                        title="Gestionar Episodios"
                      >
                        + Episodio
                      </button>
                    )}
                    <button
                      onClick={() => handleDeleteTitle(t.id)}
                      className="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors"
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
                  No hay títulos registrados en el catálogo. Usa el botón "Agregar Película o Serie" para añadir contenido local.
                </td>
              </tr>
            )}
          </tbody>
        </table>
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
    </div>
  );
};

export default CatalogManagement;
