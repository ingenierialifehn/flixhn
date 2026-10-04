import React, { useState, useEffect } from 'react';
import api from '../api/axios';
import {
  Film,
  Users,
  BarChart3,
  Plus,
  Trash2,
  RefreshCw,
  CheckCircle,
  XCircle,
  Key,
  Shield,
  Layers,
  ArrowLeft,
  Tv,
} from 'lucide-react';

const AdminDashboard = ({ onBackToBrowse }) => {
  const [activeTab, setActiveTab] = useState('overview'); // overview, catalog, subscribers
  const [stats, setStats] = useState(null);
  const [titles, setTitles] = useState([]);
  const [subscribers, setSubscribers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState('');

  // Modales
  const [showAddTitle, setShowAddTitle] = useState(false);
  const [showAddSubscriber, setShowAddSubscriber] = useState(false);
  const [showAddEpisode, setShowAddEpisode] = useState(null); // titleId
  const [showResetPass, setShowResetPass] = useState(null); // userId

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

  // Formulario Nuevo Suscriptor
  const [newSub, setNewSub] = useState({
    username: '',
    password: '',
    customer_name: '',
    assigned_ip: '',
    max_screens: 2,
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

  // Formulario Reset Password
  const [newPassword, setNewPassword] = useState('');

  const loadData = async () => {
    setLoading(true);
    try {
      const [resStats, resTitles, resSubs] = await Promise.all([
        api.get('/admin/stats'),
        api.get('/admin/titles'),
        api.get('/admin/users'),
      ]);
      setStats(resStats.data);
      setTitles(resTitles.data.titles);
      setSubscribers(resSubs.data.users);
    } catch (err) {
      console.error('Error cargando datos de administración:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleCreateTitle = async (e) => {
    e.preventDefault();
    try {
      await api.post('/admin/titles', newTitle);
      setMessage('Título agregado exitosamente al catálogo.');
      setShowAddTitle(false);
      loadData();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al guardar título');
    }
  };

  const handleDeleteTitle = async (id) => {
    if (!window.confirm('¿Seguro que deseas eliminar este título del catálogo?')) return;
    try {
      await api.delete(`/admin/titles/${id}`);
      loadData();
    } catch (err) {
      alert('Error eliminando título');
    }
  };

  const handleCreateSubscriber = async (e) => {
    e.preventDefault();
    try {
      await api.post('/admin/users', newSub);
      setMessage('Suscriptor registrado exitosamente.');
      setShowAddSubscriber(false);
      loadData();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al registrar suscriptor');
    }
  };

  const handleToggleActive = async (id) => {
    try {
      await api.post(`/admin/users/${id}/toggle-active`);
      loadData();
    } catch (err) {
      alert('Error modificando estado del suscriptor');
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    if (!newPassword || newPassword.length < 6) {
      alert('La contraseña debe tener mínimo 6 caracteres');
      return;
    }
    try {
      await api.post(`/admin/users/${showResetPass}/reset-password`, {
        password: newPassword,
      });
      alert('Contraseña restablecida correctamente.');
      setShowResetPass(null);
      setNewPassword('');
    } catch (err) {
      alert('Error al restablecer contraseña');
    }
  };

  const handleAddEpisodeSubmit = async (e) => {
    e.preventDefault();
    try {
      await api.post(`/admin/titles/${showAddEpisode.id}/episodes`, newEpisode);
      alert('Episodio agregado con éxito.');
      setShowAddEpisode(null);
      loadData();
    } catch (err) {
      alert(err.response?.data?.message || 'Error agregando episodio');
    }
  };

  return (
    <div className="min-h-screen bg-[#141414] text-white p-4 md:p-10 select-none space-y-8">
      {/* Barra Superior */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-neutral-800 pb-6">
        <div className="flex items-center gap-4">
          <button
            onClick={onBackToBrowse}
            className="flex items-center gap-2 bg-neutral-800 hover:bg-neutral-700 px-3.5 py-2 rounded text-sm font-semibold transition-colors"
          >
            <ArrowLeft className="w-4 h-4" />
            Volver al Portal
          </button>
          <div>
            <h1 className="text-2xl md:text-3xl font-black flex items-center gap-2 text-[#E50914]">
              <Shield className="w-7 h-7" />
              Consola de Administración FlixHN
            </h1>
            <p className="text-xs text-gray-400">
              Gestión centralizada de infraestructura de streaming local para ISP
            </p>
          </div>
        </div>

        {/* Pestañas de Navegación */}
        <div className="flex items-center gap-2 bg-[#181818] p-1.5 rounded-lg border border-neutral-800">
          <button
            onClick={() => setActiveTab('overview')}
            className={`px-4 py-2 rounded text-xs font-bold flex items-center gap-1.5 transition-all ${
              activeTab === 'overview' ? 'bg-[#E50914] text-white shadow-md' : 'text-gray-400 hover:text-white'
            }`}
          >
            <BarChart3 className="w-4 h-4" />
            Métricas
          </button>
          <button
            onClick={() => setActiveTab('catalog')}
            className={`px-4 py-2 rounded text-xs font-bold flex items-center gap-1.5 transition-all ${
              activeTab === 'catalog' ? 'bg-[#E50914] text-white shadow-md' : 'text-gray-400 hover:text-white'
            }`}
          >
            <Film className="w-4 h-4" />
            Catálogo ({titles.length})
          </button>
          <button
            onClick={() => setActiveTab('subscribers')}
            className={`px-4 py-2 rounded text-xs font-bold flex items-center gap-1.5 transition-all ${
              activeTab === 'subscribers' ? 'bg-[#E50914] text-white shadow-md' : 'text-gray-400 hover:text-white'
            }`}
          >
            <Users className="w-4 h-4" />
            Suscriptores ({subscribers.length})
          </button>
        </div>
      </div>

      {message && (
        <div className="p-3 bg-green-950/80 border border-green-700 rounded text-xs text-green-200 flex items-center justify-between">
          <span>{message}</span>
          <button onClick={() => setMessage('')} className="font-bold ml-4">✕</button>
        </div>
      )}

      {loading ? (
        <div className="h-64 flex items-center justify-center">
          <div className="w-10 h-10 border-4 border-red-600 border-t-transparent rounded-full animate-spin"></div>
        </div>
      ) : (
        <>
          {/* ======================================================== */}
          {/* TAB 1: MÉTRICAS Y MONITOREO EN VIVO                      */}
          {/* ======================================================== */}
          {activeTab === 'overview' && (
            <div className="space-y-8 animate-fadeIn">
              {/* Tarjetas KPI */}
              <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div className="bg-[#181818] p-5 rounded-lg border border-neutral-800 space-y-1">
                  <span className="text-xs text-gray-400">Total Títulos</span>
                  <p className="text-2xl md:text-3xl font-black text-white">{stats?.metrics?.total_titles || 0}</p>
                </div>
                <div className="bg-[#181818] p-5 rounded-lg border border-neutral-800 space-y-1">
                  <span className="text-xs text-gray-400">Películas HLS</span>
                  <p className="text-2xl md:text-3xl font-black text-white">{stats?.metrics?.total_movies || 0}</p>
                </div>
                <div className="bg-[#181818] p-5 rounded-lg border border-neutral-800 space-y-1">
                  <span className="text-xs text-gray-400">Series y Shows</span>
                  <p className="text-2xl md:text-3xl font-black text-white">{stats?.metrics?.total_series || 0}</p>
                </div>
                <div className="bg-[#181818] p-5 rounded-lg border border-neutral-800 space-y-1">
                  <span className="text-xs text-gray-400">Suscriptores ISP</span>
                  <p className="text-2xl md:text-3xl font-black text-white">{stats?.metrics?.total_subscribers || 0}</p>
                </div>
                <div className="bg-[#181818] p-5 rounded-lg border border-neutral-800 space-y-1">
                  <span className="text-xs text-gray-400">Abonados Activos</span>
                  <p className="text-2xl md:text-3xl font-black text-green-400">{stats?.metrics?.active_subscribers || 0}</p>
                </div>
              </div>

              {/* Títulos más vistos y Monitoreo de Sesiones */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Títulos más vistos */}
                <div className="bg-[#181818] p-6 rounded-lg border border-neutral-800 space-y-4">
                  <h3 className="font-bold text-base text-white flex items-center gap-2">
                    <BarChart3 className="w-5 h-5 text-red-500" />
                    Top Contenidos Más Vistos en la Red Local
                  </h3>
                  <div className="space-y-3">
                    {stats?.top_watched?.map((item, idx) => (
                      <div key={item.id} className="flex items-center justify-between p-2.5 rounded bg-neutral-900 border border-neutral-800/80">
                        <div className="flex items-center gap-3">
                          <span className="font-display text-xl font-black text-gray-500 w-5">{idx + 1}</span>
                          <img src={item.poster_url} alt={item.name} className="w-10 h-14 object-cover rounded" />
                          <div>
                            <p className="font-semibold text-sm text-white">{item.name}</p>
                            <span className="text-xs text-gray-400 uppercase">{item.type} • {item.release_year}</span>
                          </div>
                        </div>
                        <span className="text-xs font-mono bg-red-950/70 text-red-300 border border-red-800 px-2 py-1 rounded">
                          {item.playback_progresses_count} reproducciones
                        </span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Últimas reproducciones registradas */}
                <div className="bg-[#181818] p-6 rounded-lg border border-neutral-800 space-y-4">
                  <h3 className="font-bold text-base text-white flex items-center gap-2">
                    <Tv className="w-5 h-5 text-green-500" />
                    Actividad Reciente de Visualización
                  </h3>
                  <div className="space-y-2.5">
                    {stats?.recent_streams?.map((st) => (
                      <div key={st.id} className="flex items-center justify-between p-2.5 rounded bg-neutral-900 text-xs">
                        <div>
                          <p className="font-semibold text-white">
                            {st.title?.name} {st.episode ? `(Ep: ${st.episode.title})` : ''}
                          </p>
                          <p className="text-gray-400">
                            Abonado: <span className="text-gray-200">{st.profile?.user?.customer_name || st.profile?.user?.username}</span> • Perfil: {st.profile?.name}
                          </p>
                        </div>
                        <div className="text-right font-mono text-gray-400">
                          {Math.floor(st.current_seconds / 60)} min / {Math.floor(st.duration_seconds / 60)} min
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* ======================================================== */}
          {/* TAB 2: CATÁLOGO DE PELÍCULAS Y SERIES                    */}
          {/* ======================================================== */}
          {activeTab === 'catalog' && (
            <div className="space-y-6 animate-fadeIn">
              <div className="flex items-center justify-between">
                <h2 className="text-xl font-bold">Gestión de Catálogo Local</h2>
                <button
                  onClick={() => setShowAddTitle(true)}
                  className="bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded text-xs font-bold flex items-center gap-2 transition-all shadow-md"
                >
                  <Plus className="w-4 h-4" />
                  Agregar Película o Serie
                </button>
              </div>

              <div className="overflow-x-auto bg-[#181818] rounded-lg border border-neutral-800">
                <table className="w-full text-left text-xs">
                  <thead className="bg-neutral-900 text-gray-400 uppercase border-b border-neutral-800 font-semibold">
                    <tr>
                      <th className="p-3">Título</th>
                      <th className="p-3">Tipo</th>
                      <th className="p-3">Género / Año</th>
                      <th className="p-3">Destacado</th>
                      <th className="p-3">Ruta de Streaming</th>
                      <th className="p-3 text-right">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-800">
                    {titles.map((t) => (
                      <tr key={t.id} className="hover:bg-neutral-800/40">
                        <td className="p-3 flex items-center gap-3">
                          <img src={t.poster_url} alt={t.name} className="w-8 h-12 object-cover rounded" />
                          <div>
                            <p className="font-bold text-white text-sm">{t.name}</p>
                            <p className="text-gray-400 truncate max-w-xs">{t.description}</p>
                          </div>
                        </td>
                        <td className="p-3">
                          <span className={`px-2 py-0.5 rounded font-mono uppercase text-[10px] ${
                            t.type === 'movie' ? 'bg-blue-900/60 text-blue-300' : 'bg-purple-900/60 text-purple-300'
                          }`}>
                            {t.type}
                          </span>
                        </td>
                        <td className="p-3 text-gray-300">
                          {t.genre} • {t.release_year}
                        </td>
                        <td className="p-3">
                          {t.is_featured ? (
                            <span className="text-green-400 font-bold">★ Sí</span>
                          ) : (
                            <span className="text-gray-500">No</span>
                          )}
                        </td>
                        <td className="p-3 font-mono text-[11px] text-gray-400 truncate max-w-xs">
                          {t.type === 'movie' ? t.stream_path : `${t.seasons?.length || 0} Temporada(s)`}
                        </td>
                        <td className="p-3 text-right space-x-2">
                          {t.type === 'series' && (
                            <button
                              onClick={() => {
                                setShowAddEpisode(t);
                                setNewEpisode((prev) => ({
                                  ...prev,
                                  season_id: t.seasons?.[0]?.id || '',
                                }));
                              }}
                              className="text-purple-400 hover:text-purple-300 font-semibold p-1"
                              title="Gestionar Episodios"
                            >
                              + Episodio
                            </button>
                          )}
                          <button
                            onClick={() => handleDeleteTitle(t.id)}
                            className="text-red-500 hover:text-red-400 p-1"
                            title="Eliminar título"
                          >
                            <Trash2 className="w-4 h-4 inline" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ======================================================== */}
          {/* TAB 3: GESTIÓN DE SUSCRIPTORES ISP                       */}
          {/* ======================================================== */}
          {activeTab === 'subscribers' && (
            <div className="space-y-6 animate-fadeIn">
              <div className="flex items-center justify-between">
                <h2 className="text-xl font-bold">Gestión de Suscriptores / Clientes ISP</h2>
                <button
                  onClick={() => setShowAddSubscriber(true)}
                  className="bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded text-xs font-bold flex items-center gap-2 transition-all shadow-md"
                >
                  <Plus className="w-4 h-4" />
                  Nuevo Suscriptor
                </button>
              </div>

              <div className="overflow-x-auto bg-[#181818] rounded-lg border border-neutral-800">
                <table className="w-full text-left text-xs">
                  <thead className="bg-neutral-900 text-gray-400 uppercase border-b border-neutral-800 font-semibold">
                    <tr>
                      <th className="p-3">Usuario (Login)</th>
                      <th className="p-3">Nombre del Cliente / Contrato</th>
                      <th className="p-3">Rol</th>
                      <th className="p-3">IP Asignada ISP</th>
                      <th className="p-3">Pantallas Máx.</th>
                      <th className="p-3">Perfiles</th>
                      <th className="p-3">Estado</th>
                      <th className="p-3 text-right">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-800">
                    {subscribers.map((u) => (
                      <tr key={u.id} className="hover:bg-neutral-800/40">
                        <td className="p-3 font-mono font-bold text-white">{u.username}</td>
                        <td className="p-3 text-gray-200">{u.customer_name || 'N/D'}</td>
                        <td className="p-3">
                          <span className={`px-2 py-0.5 rounded font-mono uppercase text-[10px] ${
                            u.role === 'admin' ? 'bg-red-900/60 text-red-300 font-bold' : 'bg-gray-800 text-gray-300'
                          }`}>
                            {u.role}
                          </span>
                        </td>
                        <td className="p-3 font-mono text-gray-300">{u.assigned_ip || 'Cualquiera'}</td>
                        <td className="p-3 font-mono text-gray-300">{u.max_screens} pantallas</td>
                        <td className="p-3">
                          <div className="flex items-center gap-1">
                            {u.profiles?.map((p) => (
                              <span
                                key={p.id}
                                className="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                                style={{ backgroundColor: p.avatar_color }}
                                title={p.name}
                              >
                                {p.name.charAt(0)}
                              </span>
                            ))}
                          </div>
                        </td>
                        <td className="p-3">
                          {u.is_active ? (
                            <span className="flex items-center gap-1 text-green-400 font-semibold">
                              <CheckCircle className="w-3.5 h-3.5" /> Activo
                            </span>
                          ) : (
                            <span className="flex items-center gap-1 text-red-400 font-semibold">
                              <XCircle className="w-3.5 h-3.5" /> Suspendido
                            </span>
                          )}
                        </td>
                        <td className="p-3 text-right space-x-2">
                          <button
                            onClick={() => handleToggleActive(u.id)}
                            className="text-xs text-yellow-400 hover:text-yellow-300 font-semibold p-1"
                          >
                            {u.is_active ? 'Suspender' : 'Activar'}
                          </button>
                          <button
                            onClick={() => setShowResetPass(u.id)}
                            className="text-xs text-blue-400 hover:text-blue-300 font-semibold p-1"
                            title="Restablecer clave"
                          >
                            <Key className="w-3.5 h-3.5 inline mr-1" />
                            Clave
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}

      {/* ======================================================== */}
      {/* MODAL: REGISTRAR NUEVO TÍTULO                            */}
      {/* ======================================================== */}
      {showAddTitle && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-neutral-700 rounded-lg max-w-lg w-full p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-bold text-white">Nuevo Título en Catálogo</h3>
            <form onSubmit={handleCreateTitle} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-300 block mb-1">Nombre</label>
                <input
                  type="text"
                  required
                  value={newTitle.name}
                  onChange={(e) => setNewTitle({ ...newTitle, name: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2 focus:ring-1 focus:ring-red-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-gray-300 block mb-1">Tipo</label>
                  <select
                    value={newTitle.type}
                    onChange={(e) => setNewTitle({ ...newTitle, type: e.target.value })}
                    className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                  >
                    <option value="movie">Película</option>
                    <option value="series">Serie</option>
                  </select>
                </div>
                <div>
                  <label className="text-gray-300 block mb-1">Año de Estreno</label>
                  <input
                    type="number"
                    value={newTitle.release_year}
                    onChange={(e) => setNewTitle({ ...newTitle, release_year: parseInt(e.target.value) })}
                    className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                  />
                </div>
              </div>

              <div>
                <label className="text-gray-300 block mb-1">Género</label>
                <input
                  type="text"
                  value={newTitle.genre}
                  onChange={(e) => setNewTitle({ ...newTitle, genre: e.target.value })}
                  placeholder="Acción, Ciencia Ficción, Documental..."
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">Sinopsis</label>
                <textarea
                  rows={3}
                  value={newTitle.description}
                  onChange={(e) => setNewTitle({ ...newTitle, description: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">URL de Poster (Vertical)</label>
                <input
                  type="text"
                  required
                  value={newTitle.poster_url}
                  onChange={(e) => setNewTitle({ ...newTitle, poster_url: e.target.value })}
                  placeholder="https://... o /storage/posters/..."
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">URL de Backdrop (Horizontal)</label>
                <input
                  type="text"
                  required
                  value={newTitle.backdrop_url}
                  onChange={(e) => setNewTitle({ ...newTitle, backdrop_url: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              {newTitle.type === 'movie' && (
                <div>
                  <label className="text-gray-300 block mb-1">Ruta HLS / MP4 (.m3u8 o local /media/)</label>
                  <input
                    type="text"
                    value={newTitle.stream_path}
                    onChange={(e) => setNewTitle({ ...newTitle, stream_path: e.target.value })}
                    placeholder="movies/demo.m3u8 o https://..."
                    className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                  />
                </div>
              )}

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="featCheck"
                  checked={newTitle.is_featured}
                  onChange={(e) => setNewTitle({ ...newTitle, is_featured: e.target.checked })}
                  className="accent-[#E50914] w-4 h-4 rounded"
                />
                <label htmlFor="featCheck" className="text-gray-300 cursor-pointer">
                  Destacar en el Billboard Principal
                </label>
              </div>

              <div className="flex items-center justify-end gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setShowAddTitle(false)}
                  className="px-4 py-2 font-semibold text-gray-300 hover:text-white"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 font-bold bg-[#E50914] text-white rounded hover:bg-[#F40612]"
                >
                  Guardar Título
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ======================================================== */}
      {/* MODAL: REGISTRAR NUEVO SUSCRIPTOR                        */}
      {/* ======================================================== */}
      {showAddSubscriber && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-neutral-700 rounded-lg max-w-md w-full p-6 space-y-4 shadow-2xl">
            <h3 className="text-lg font-bold text-white">Nuevo Suscriptor de Red ISP</h3>
            <form onSubmit={handleCreateSubscriber} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-300 block mb-1">Nombre Completo del Titular</label>
                <input
                  type="text"
                  required
                  value={newSub.customer_name}
                  onChange={(e) => setNewSub({ ...newSub, customer_name: e.target.value })}
                  placeholder="Ej: Familia Morales / Carlos Gómez"
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">Usuario de Acceso (Username)</label>
                <input
                  type="text"
                  required
                  value={newSub.username}
                  onChange={(e) => setNewSub({ ...newSub, username: e.target.value })}
                  placeholder="ej: cliente2 o abonado109"
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">Contraseña Inicial</label>
                <input
                  type="password"
                  required
                  value={newSub.password}
                  onChange={(e) => setNewSub({ ...newSub, password: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">IP Local Asignada (Opcional para candado On-Net)</label>
                <input
                  type="text"
                  value={newSub.assigned_ip}
                  onChange={(e) => setNewSub({ ...newSub, assigned_ip: e.target.value })}
                  placeholder="Ej: 192.168.100.50 (dejar en blanco para cualquier IP)"
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div>
                <label className="text-gray-300 block mb-1">Límite de Pantallas Simultáneas</label>
                <input
                  type="number"
                  min={1}
                  max={8}
                  value={newSub.max_screens}
                  onChange={(e) => setNewSub({ ...newSub, max_screens: parseInt(e.target.value) })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setShowAddSubscriber(false)}
                  className="px-4 py-2 font-semibold text-gray-300 hover:text-white"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 font-bold bg-[#E50914] text-white rounded hover:bg-[#F40612]"
                >
                  Registrar Suscriptor
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL RESET PASSWORD */}
      {showResetPass && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-neutral-700 rounded-lg max-w-sm w-full p-6 space-y-4">
            <h3 className="text-base font-bold text-white">Restablecer Contraseña</h3>
            <form onSubmit={handleResetPassword} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-300 block mb-1">Nueva Contraseña</label>
                <input
                  type="password"
                  required
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="Mínimo 6 caracteres"
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>
              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setShowResetPass(null)}
                  className="px-3 py-1.5 text-gray-300"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 bg-[#E50914] text-white font-bold rounded"
                >
                  Actualizar Clave
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL AÑADIR EPISODIO A SERIE */}
      {showAddEpisode && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-neutral-700 rounded-lg max-w-md w-full p-6 space-y-4">
            <h3 className="text-base font-bold text-white">
              Añadir Episodio a: {showAddEpisode.name}
            </h3>
            <form onSubmit={handleAddEpisodeSubmit} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-300 block mb-1">Temporada</label>
                <select
                  value={newEpisode.season_id}
                  onChange={(e) => setNewEpisode({ ...newEpisode, season_id: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                >
                  {showAddEpisode.seasons?.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.title}
                    </option>
                  ))}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="text-gray-300 block mb-1">Número de Episodio</label>
                  <input
                    type="number"
                    value={newEpisode.episode_number}
                    onChange={(e) => setNewEpisode({ ...newEpisode, episode_number: parseInt(e.target.value) })}
                    className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                  />
                </div>
                <div>
                  <label className="text-gray-300 block mb-1">Duración (Segundos)</label>
                  <input
                    type="number"
                    value={newEpisode.duration_seconds}
                    onChange={(e) => setNewEpisode({ ...newEpisode, duration_seconds: parseInt(e.target.value) })}
                    className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                  />
                </div>
              </div>
              <div>
                <label className="text-gray-300 block mb-1">Título del Episodio</label>
                <input
                  type="text"
                  required
                  value={newEpisode.title}
                  onChange={(e) => setNewEpisode({ ...newEpisode, title: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>
              <div>
                <label className="text-gray-300 block mb-1">Ruta HLS / MP4</label>
                <input
                  type="text"
                  required
                  value={newEpisode.stream_path}
                  onChange={(e) => setNewEpisode({ ...newEpisode, stream_path: e.target.value })}
                  placeholder="series/cosmos/s01e01/index.m3u8 o https://..."
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>
              <div>
                <label className="text-gray-300 block mb-1">URL Miniatura (Thumbnail)</label>
                <input
                  type="text"
                  value={newEpisode.thumbnail_url}
                  onChange={(e) => setNewEpisode({ ...newEpisode, thumbnail_url: e.target.value })}
                  className="w-full bg-[#242424] text-white border border-neutral-700 rounded p-2"
                />
              </div>
              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setShowAddEpisode(null)}
                  className="px-3 py-1.5 text-gray-300"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 bg-[#E50914] text-white font-bold rounded"
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

export default AdminDashboard;
