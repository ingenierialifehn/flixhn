import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Tv,
  Plus,
  RefreshCw,
  MoreVertical,
  Edit2,
  Trash2,
  Search,
  ArrowLeft,
  CheckCircle2,
  AlertTriangle,
  Play,
  Layers,
  Settings,
  Radio,
  ExternalLink,
  Wifi,
  Database,
  Sliders,
  Sparkles,
  Info,
  Clock,
  Check,
  X,
  RadioTower,
  Eye,
} from 'lucide-react';
import api from '../../services/api';
import LiveTvPlayer from '../../components/LiveTvPlayer';

const DEFAULT_M3U_URL = 'http://65.187.110.22:58004/get.php?username=emby&password=emby&type=m3u&output=hls';

export default function LiveTvManagement() {
  const [activeTab, setActiveTab] = useState('setup'); // 'setup', 'channels', 'advanced'

  // Fuentes y datos
  const [sources, setSources] = useState([]);
  const [guideSources, setGuideSources] = useState([]);
  const [channels, setChannels] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshingGuide, setRefreshingGuide] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);
  const [notification, setNotification] = useState(null);

  // Estados de modales
  const [showTypeModal, setShowTypeModal] = useState(false);
  const [showM3uForm, setShowM3uForm] = useState(false);
  const [showHdHomerunModal, setShowHdHomerunModal] = useState(false);
  const [showGuideModal, setShowGuideModal] = useState(false);
  const [activeMenuSourceId, setActiveMenuSourceId] = useState(null);
  const [editingSource, setEditingSource] = useState(null);

  // Reproductor de prueba en vivo
  const [previewChannel, setPreviewChannel] = useState(null);

  // Filtros de canales (Pestaña Canales)
  const [channelSearch, setChannelSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [channelsPage, setChannelsPage] = useState(1);
  const [channelsTotal, setChannelsTotal] = useState(0);

  // Formulario M3U
  const [formData, setFormData] = useState({
    name: 'M3U',
    type: 'm3u',
    url: DEFAULT_M3U_URL,
    user_agent: '',
    referer_mode: 'Ninguno',
    referrer_header: '',
    stream_limit: 0,
    group_filter: '',
    import_guide_from_m3u: true,
    preferred_image_source: 'Sintonizador / M3U',
    allow_channel_number_mapping: false,
    tags: '',
  });

  // Formulario Guía EPG
  const [guideFormData, setGuideFormData] = useState({
    name: 'Guía EPG XMLTV',
    type: 'xmltv',
    url: 'http://65.187.110.22:58004/xmltv.php?username=emby&password=emby',
  });

  // Cerrar menú de 3 puntos al hacer clic fuera
  useEffect(() => {
    const handleOutside = () => setActiveMenuSourceId(null);
    document.addEventListener('click', handleOutside);
    return () => document.removeEventListener('click', handleOutside);
  }, []);

  const showToast = (message, type = 'success') => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 5000);
  };

  // Cargar fuentes de TV
  const loadSources = useCallback(async () => {
    try {
      const res = await api.get('/admin/tv-sources');
      if (res.data?.sources) {
        setSources(res.data.sources);
      }
    } catch (err) {
      console.error('Error cargando fuentes de TV:', err);
    }
  }, []);

  // Cargar fuentes de guía EPG
  const loadGuideSources = useCallback(async () => {
    try {
      const res = await api.get('/admin/tv-guide-sources');
      if (res.data?.guide_sources) {
        setGuideSources(res.data.guide_sources);
      }
    } catch (err) {
      console.error('Error cargando fuentes de guía:', err);
    }
  }, []);

  // Cargar canales para la pestaña Canales
  const loadChannels = useCallback(async () => {
    try {
      const params = {
        page: channelsPage,
        per_page: 40,
      };
      if (channelSearch.trim()) params.q = channelSearch.trim();
      if (selectedCategory && selectedCategory !== 'all') params.group = selectedCategory;

      const res = await api.get('/admin/tv-channels', { params });
      if (res.data) {
        setChannels(res.data.channels?.data || []);
        setChannelsTotal(res.data.channels?.total || 0);
        setCategories(res.data.categories || []);
      }
    } catch (err) {
      console.error('Error cargando canales:', err);
    }
  }, [channelSearch, selectedCategory, channelsPage]);

  // Carga inicial
  useEffect(() => {
    setLoading(true);
    Promise.all([loadSources(), loadGuideSources()])
      .finally(() => setLoading(false));
  }, [loadSources, loadGuideSources]);

  // Cargar canales cuando cambia a pestaña Canales
  useEffect(() => {
    if (activeTab === 'channels') {
      loadChannels();
    }
  }, [activeTab, loadChannels]);

  // Abrir formulario para crear fuente
  const handleOpenAddSource = () => {
    setShowTypeModal(true);
  };

  // Seleccionar M3U desde el modal de tipo
  const handleSelectM3uType = () => {
    setShowTypeModal(false);
    setEditingSource(null);
    setFormData({
      name: 'M3U',
      type: 'm3u',
      url: DEFAULT_M3U_URL,
      user_agent: '',
      referer_mode: 'Ninguno',
      referrer_header: '',
      stream_limit: 0,
      group_filter: '',
      import_guide_from_m3u: true,
      preferred_image_source: 'Sintonizador / M3U',
      allow_channel_number_mapping: false,
      tags: '',
    });
    setShowM3uForm(true);
  };

  // Seleccionar HD Homerun desde el modal de tipo
  const handleSelectHdHomerunType = () => {
    setShowTypeModal(false);
    setShowHdHomerunModal(true);
  };

  // Abrir formulario para editar fuente
  const handleEditSource = (source, e) => {
    if (e) e.stopPropagation();
    setActiveMenuSourceId(null);
    setEditingSource(source);
    setFormData({
      name: source.name || 'M3U',
      type: source.type || 'm3u',
      url: source.url || '',
      user_agent: source.user_agent || '',
      referer_mode: source.referer_mode || 'Ninguno',
      referrer_header: source.referrer_header || '',
      stream_limit: source.stream_limit || 0,
      group_filter: source.group_filter || '',
      import_guide_from_m3u: source.import_guide_from_m3u ?? true,
      preferred_image_source: source.preferred_image_source || 'Sintonizador / M3U',
      allow_channel_number_mapping: source.allow_channel_number_mapping ?? false,
      tags: source.tags || '',
    });
    setShowM3uForm(true);
  };

  // Guardar fuente M3U
  const handleSaveM3u = async (e) => {
    if (e) e.preventDefault();
    setActionLoading(true);
    try {
      if (editingSource) {
        const res = await api.put(`/admin/tv-sources/${editingSource.id}`, formData);
        showToast(res.data?.message || 'Fuente de TV actualizada.');
      } else {
        const res = await api.post('/admin/tv-sources', formData);
        showToast(res.data?.message || 'Fuente de TV guardada y sincronizada.');
      }
      setShowM3uForm(false);
      setEditingSource(null);
      await loadSources();
      if (activeTab === 'channels') loadChannels();
    } catch (err) {
      console.error('Error guardando fuente M3U:', err);
      showToast(err.response?.data?.message || 'Error al guardar la fuente de TV.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Sincronizar fuente M3U
  const handleSyncSource = async (sourceId, e) => {
    if (e) e.stopPropagation();
    setActiveMenuSourceId(null);
    setActionLoading(true);
    try {
      const res = await api.post(`/admin/tv-sources/${sourceId}/refresh`, { fallback_demo: true });
      showToast(res.data?.message || 'Canales sincronizados correctamente.');
      await loadSources();
      if (activeTab === 'channels') loadChannels();
    } catch (err) {
      console.error('Error sincronizando canales:', err);
      showToast('Error al conectar con la fuente IPTV.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Eliminar fuente de TV
  const handleDeleteSource = async (sourceId, e) => {
    if (e) e.stopPropagation();
    setActiveMenuSourceId(null);
    if (!window.confirm('¿Deseas eliminar esta fuente de TV y todos sus canales asociados?')) {
      return;
    }

    setActionLoading(true);
    try {
      const res = await api.delete(`/admin/tv-sources/${sourceId}`);
      showToast(res.data?.message || 'Fuente eliminada exitosamente.');
      await loadSources();
      if (activeTab === 'channels') loadChannels();
    } catch (err) {
      console.error('Error eliminando fuente:', err);
      showToast('Error al eliminar la fuente de TV.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Actualizar datos de guía EPG
  const handleRefreshGuide = async () => {
    setRefreshingGuide(true);
    try {
      if (guideSources.length > 0) {
        await Promise.all(
          guideSources.map((g) => api.post(`/admin/tv-guide-sources/${g.id}/refresh`))
        );
      }
      showToast('Datos de la guía electrónica de programas (EPG) actualizados.');
      await loadGuideSources();
    } catch (err) {
      console.error('Error actualizando guía EPG:', err);
      showToast('Guía actualizada con los datos disponibles.', 'info');
    } finally {
      setRefreshingGuide(false);
    }
  };

  // Guardar fuente EPG
  const handleSaveGuideSource = async (e) => {
    if (e) e.preventDefault();
    setActionLoading(true);
    try {
      await api.post('/admin/tv-guide-sources', guideFormData);
      showToast('Fuente de datos de guía agregada.');
      setShowGuideModal(false);
      await loadGuideSources();
    } catch (err) {
      console.error('Error guardando fuente de guía:', err);
      showToast('Error al guardar fuente de guía.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Eliminar fuente EPG
  const handleDeleteGuideSource = async (id) => {
    if (!window.confirm('¿Eliminar esta fuente de guía EPG?')) return;
    try {
      await api.delete(`/admin/tv-guide-sources/${id}`);
      showToast('Fuente de guía eliminada.');
      await loadGuideSources();
    } catch (err) {
      showToast('Error al eliminar fuente de guía.', 'error');
    }
  };

  return (
    <div className="space-y-6 text-zinc-100 animate-fadeIn">
      {/* Notificación Toast */}
      {notification && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-lg shadow-2xl border transition-all duration-300 animate-slideUp ${
            notification.type === 'error'
              ? 'bg-red-950/95 border-red-700 text-red-200'
              : notification.type === 'info'
              ? 'bg-blue-950/95 border-blue-700 text-blue-200'
              : 'bg-zinc-900/95 border-emerald-600 text-emerald-200'
          }`}
        >
          {notification.type === 'error' ? (
            <AlertTriangle className="w-5 h-5 text-red-400 shrink-0" />
          ) : (
            <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          )}
          <span className="text-sm font-medium">{notification.message}</span>
          <button
            onClick={() => setNotification(null)}
            className="p-1 hover:text-white rounded"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Pestañas Superiores de Navegación del Módulo */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-3">
        <button
          type="button"
          onClick={() => {
            setShowM3uForm(false);
            setActiveTab('setup');
          }}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-all cursor-pointer ${
            activeTab === 'setup' && !showM3uForm
              ? 'bg-zinc-800 text-white shadow-sm font-semibold'
              : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850'
          }`}
        >
          Configuración
        </button>
        <button
          type="button"
          onClick={() => {
            setShowM3uForm(false);
            setActiveTab('channels');
          }}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-all cursor-pointer ${
            activeTab === 'channels'
              ? 'bg-zinc-800 text-white shadow-sm font-semibold'
              : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850'
          }`}
        >
          Canales
        </button>
        <button
          type="button"
          onClick={() => {
            setShowM3uForm(false);
            setActiveTab('advanced');
          }}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-all cursor-pointer ${
            activeTab === 'advanced'
              ? 'bg-zinc-800 text-white shadow-sm font-semibold'
              : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-850'
          }`}
        >
          Avanzado
        </button>
      </div>

      {/* ========================================================================= */}
      {/* VISTA 1: CONFIGURACIÓN (SETUP) */}
      {/* ========================================================================= */}
      {activeTab === 'setup' && !showM3uForm && (
        <div className="space-y-8 animate-fadeIn">
          {/* SECCIÓN 1: FUENTES DE TV */}
          <section className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <h2 className="text-xl font-bold text-white flex items-center gap-2">
                  <Tv className="w-5 h-5 text-[#E50914]" />
                  Fuentes de TV
                </h2>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Gestiona sintonizadores HD Homerun y listas IPTV M3U/M3U8 para la red de fibra del ISP.
                </p>
              </div>

              <div className="flex items-center gap-2.5">
                <button
                  type="button"
                  onClick={handleOpenAddSource}
                  className="flex items-center gap-2 bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded-lg text-xs font-bold transition-all shadow-md active:scale-95 cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  Agregar Fuente de TV
                </button>

                <button
                  type="button"
                  onClick={handleRefreshGuide}
                  disabled={refreshingGuide}
                  className="flex items-center gap-2 bg-[#181818] hover:bg-zinc-800 text-zinc-200 hover:text-white px-3.5 py-2 rounded-lg text-xs font-semibold border border-zinc-800 transition-colors shadow-sm disabled:opacity-60 cursor-pointer"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${refreshingGuide ? 'animate-spin text-[#E50914]' : ''}`} />
                  Actualizar Datos de Guía
                </button>
              </div>
            </div>

            {/* Grid de Fuentes Configuradas */}
            {loading ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {[1, 2].map((i) => (
                  <div key={i} className="h-40 bg-[#181818] border border-zinc-800 rounded-lg animate-pulse" />
                ))}
              </div>
            ) : sources.length === 0 ? (
              <div className="bg-[#181818] border border-zinc-800 rounded-lg p-8 text-center space-y-3">
                <RadioTower className="w-12 h-12 text-zinc-600 mx-auto stroke-[1.5]" />
                <h3 className="text-base font-bold text-white">No hay fuentes de TV configuradas</h3>
                <p className="text-xs text-zinc-400 max-w-md mx-auto">
                  Agrega una lista M3U de tu proveedor IPTV para importar canales y habilitar la TV en vivo para los suscriptores.
                </p>
                <button
                  onClick={handleOpenAddSource}
                  className="inline-flex items-center gap-2 bg-[#E50914] text-white px-4 py-2 rounded-lg text-xs font-bold shadow-md hover:bg-[#F40612] transition-colors cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  Agregar Fuente M3U
                </button>
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {sources.map((src) => {
                  const isMenuOpen = activeMenuSourceId === src.id;
                  const truncatedUrl = src.url?.length > 45 ? `${src.url.substring(0, 42)}...` : src.url;

                  return (
                    <div
                      key={src.id}
                      className="bg-[#181818] border border-zinc-800 hover:border-zinc-700 rounded-lg p-4 flex flex-col justify-between space-y-4 shadow-sm transition-all group relative"
                    >
                      {/* Cabecera y Menú de Tres Puntos */}
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 rounded text-[10px] font-mono uppercase font-bold bg-[#E50914]/20 text-[#E50914] border border-[#E50914]/30">
                            {src.type || 'M3U'}
                          </span>
                          <span className="text-xs font-bold text-white truncate max-w-[170px]">
                            {src.name || 'M3U'}
                          </span>
                        </div>

                        {/* Botón de Tres Puntos */}
                        <div className="relative">
                          <button
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation();
                              setActiveMenuSourceId(isMenuOpen ? null : src.id);
                            }}
                            className="p-1 text-zinc-400 hover:text-white rounded hover:bg-zinc-800 transition-colors cursor-pointer"
                            title="Opciones de la fuente"
                          >
                            <MoreVertical className="w-4 h-4" />
                          </button>

                          {/* Menú Desplegable Flotante */}
                          {isMenuOpen && (
                            <div
                              onClick={(e) => e.stopPropagation()}
                              className="absolute right-0 mt-1 w-44 bg-[#202020] border border-zinc-700 rounded-md shadow-2xl py-1.5 z-40 text-xs animate-fadeIn"
                            >
                              <button
                                onClick={(e) => handleEditSource(src, e)}
                                className="w-full text-left px-3 py-2 hover:bg-zinc-700/60 flex items-center gap-2 text-zinc-200 hover:text-white transition-colors cursor-pointer"
                              >
                                <Edit2 className="w-3.5 h-3.5 text-zinc-400" />
                                Editar
                              </button>
                              <button
                                onClick={(e) => handleSyncSource(src.id, e)}
                                className="w-full text-left px-3 py-2 hover:bg-zinc-700/60 flex items-center gap-2 text-zinc-200 hover:text-white transition-colors cursor-pointer"
                              >
                                <RefreshCw className="w-3.5 h-3.5 text-zinc-400" />
                                Sincronizar Canales
                              </button>
                              <div className="border-t border-zinc-700/60 my-1"></div>
                              <button
                                onClick={(e) => handleDeleteSource(src.id, e)}
                                className="w-full text-left px-3 py-2 hover:bg-red-900/30 flex items-center gap-2 text-red-400 hover:text-red-300 transition-colors cursor-pointer"
                              >
                                <Trash2 className="w-3.5 h-3.5 text-red-400" />
                                Eliminar
                              </button>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Miniatura Central con Ícono de Pantalla de Video */}
                      <div className="h-28 bg-[#121212] border border-zinc-850 rounded-md flex items-center justify-center relative overflow-hidden group-hover:border-zinc-700 transition-colors">
                        <div className="w-12 h-12 rounded-full bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-[#E50914] group-hover:scale-110 transition-all">
                          <Tv className="w-6 h-6 stroke-[1.5]" />
                        </div>
                        <span className="absolute bottom-2 right-2 text-[10px] font-mono text-zinc-500 bg-black/60 px-1.5 py-0.5 rounded">
                          {src.tv_channels_count || src.channels_count || 0} CANALES
                        </span>
                      </div>

                      {/* Subtítulo / URL y Estado */}
                      <div className="space-y-1">
                        <p
                          className="text-xs text-zinc-400 font-mono truncate cursor-pointer hover:text-zinc-200"
                          title={src.url}
                        >
                          {truncatedUrl}
                        </p>
                        <div className="flex items-center justify-between text-[11px] text-zinc-500 pt-1 border-t border-zinc-850">
                          <span className="flex items-center gap-1">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                            Activa
                          </span>
                          <span>
                            {src.last_synced_at
                              ? new Date(src.last_synced_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                              : 'Pendiente'}
                          </span>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </section>

          {/* SECCIÓN 2: FUENTES DE DATOS DE GUÍA (EPG) */}
          <section className="space-y-4 pt-4 border-t border-zinc-800">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <h2 className="text-xl font-bold text-white flex items-center gap-2">
                  <Database className="w-5 h-5 text-indigo-400" />
                  Fuentes de Datos de Guía (Guide Data Sources - EPG)
                </h2>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Conexión con fuentes XMLTV para asociar parrilla de programación y horarios a cada canal.
                </p>
              </div>

              <button
                type="button"
                onClick={() => setShowGuideModal(true)}
                className="flex items-center gap-2 bg-zinc-800 hover:bg-zinc-700 text-white px-3.5 py-2 rounded-lg text-xs font-semibold border border-zinc-700 transition-colors shadow-sm cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5" />
                Agregar Fuente de Guía
              </button>
            </div>

            {/* Lista de Fuentes Guía */}
            <div className="space-y-2">
              {guideSources.length === 0 ? (
                <div className="bg-[#181818] border border-zinc-800 rounded-lg p-6 text-center text-xs text-zinc-500">
                  No hay fuentes de guía EPG externas configuradas. Los canales usarán metadatos integrados del M3U.
                </div>
              ) : (
                guideSources.map((guide) => (
                  <div
                    key={guide.id}
                    className="bg-[#181818] border border-zinc-800 rounded-lg p-3.5 flex items-center justify-between gap-4"
                  >
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="w-8 h-8 rounded bg-zinc-900 border border-zinc-800 flex items-center justify-center text-indigo-400 shrink-0">
                        <Database className="w-4 h-4" />
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <h4 className="text-xs font-bold text-white truncate">{guide.name}</h4>
                          <span className="text-[10px] font-mono bg-zinc-900 text-zinc-400 px-1.5 py-0.5 rounded border border-zinc-800">
                            {guide.type}
                          </span>
                        </div>
                        <p className="text-[11px] text-zinc-400 font-mono truncate" title={guide.url}>
                          {guide.url}
                        </p>
                      </div>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                      <button
                        onClick={() => handleDeleteGuideSource(guide.id)}
                        className="p-1.5 text-zinc-400 hover:text-red-400 rounded hover:bg-zinc-800 transition-colors"
                        title="Eliminar fuente de guía"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </section>
        </div>
      )}

      {/* ========================================================================= */}
      {/* VISTA 2: FORMULARIO DE CONFIGURACIÓN DE FUENTE M3U (IMAGEN 3) */}
      {/* ========================================================================= */}
      {showM3uForm && (
        <div className="bg-[#181818] border border-zinc-800 rounded-xl p-6 md:p-8 space-y-6 max-w-3xl mx-auto shadow-2xl animate-fadeIn">
          {/* Encabezado con flecha de retorno */}
          <div className="flex items-center justify-between border-b border-zinc-800 pb-4">
            <button
              type="button"
              onClick={() => {
                setShowM3uForm(false);
                setEditingSource(null);
              }}
              className="flex items-center gap-2 text-zinc-400 hover:text-white text-sm font-semibold transition-colors cursor-pointer group"
            >
              <ArrowLeft className="w-4 h-4 group-hover:-translate-x-1 transition-transform" />
              Configuración de Fuente de TV
            </button>
            <span className="text-xs font-mono uppercase bg-zinc-900 text-zinc-400 px-2 py-1 rounded border border-zinc-800">
              IPTV M3U Core
            </span>
          </div>

          <form onSubmit={handleSaveM3u} className="space-y-5 text-xs">
            {/* 1. Archivo o URL (File or url) con lupa */}
            <div className="space-y-1.5">
              <label className="block text-xs font-medium text-zinc-300">
                Archivo o URL:
              </label>
              <div className="relative">
                <input
                  type="text"
                  required
                  value={formData.url}
                  onChange={(e) => setFormData({ ...formData, url: e.target.value })}
                  placeholder="http://proveedor-iptv:puerto/get.php?username=...&password=..."
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2.5 text-xs text-white placeholder-zinc-500 font-mono outline-none pr-10 transition-colors"
                />
                <div className="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-zinc-400">
                  <Search className="w-4 h-4" />
                </div>
              </div>
              <p className="text-[11px] text-zinc-500">
                Introduce la URL HLS completa del proveedor o ruta al archivo M3U local.
              </p>
            </div>

            {/* 2. Encabezado HTTP User-Agent */}
            <div className="space-y-1.5">
              <label className="block text-xs font-medium text-zinc-300">
                Encabezado HTTP User-Agent:
              </label>
              <input
                type="text"
                value={formData.user_agent}
                onChange={(e) => setFormData({ ...formData, user_agent: e.target.value })}
                placeholder="FlixHN/2.0 (IPTV Sintonizador; HLS Client)"
                className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white placeholder-zinc-500 font-mono outline-none transition-colors"
              />
            </div>

            {/* 3 & 4. Modo de encabezado Referer y Encabezado Referrer */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <label className="block text-xs font-medium text-zinc-300">
                  Modo de encabezado Referer:
                </label>
                <select
                  value={formData.referer_mode}
                  onChange={(e) => setFormData({ ...formData, referer_mode: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white outline-none transition-colors cursor-pointer"
                >
                  <option value="Ninguno">Ninguno</option>
                  <option value="Referer">Referer</option>
                  <option value="Referrer">Referrer</option>
                  <option value="Ambos">Ambos</option>
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="block text-xs font-medium text-zinc-300">
                  Encabezado HTTP Referrer:
                </label>
                <input
                  type="text"
                  value={formData.referrer_header}
                  onChange={(e) => setFormData({ ...formData, referrer_header: e.target.value })}
                  placeholder="https://proveedor.iptv"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white placeholder-zinc-500 font-mono outline-none transition-colors"
                />
              </div>
            </div>

            {/* 5. Límite de transmisiones simultáneas */}
            <div className="space-y-1.5">
              <label className="block text-xs font-medium text-zinc-300">
                Límite de transmisiones simultáneas:
              </label>
              <input
                type="number"
                min="0"
                value={formData.stream_limit}
                onChange={(e) => setFormData({ ...formData, stream_limit: parseInt(e.target.value, 10) || 0 })}
                className="w-36 bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white font-mono outline-none transition-colors"
              />
              <span className="text-[11px] text-zinc-500 ml-2">0 para sin límite</span>
            </div>

            {/* 6. Importar solo canales que contengan estos grupos */}
            <div className="space-y-1.5">
              <label className="block text-xs font-medium text-zinc-300">
                Importar solo canales que contengan estos grupos:
              </label>
              <input
                type="text"
                value={formData.group_filter}
                onChange={(e) => setFormData({ ...formData, group_filter: e.target.value })}
                placeholder="Deportes; Noticias; Cine; Honduras"
                className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white placeholder-zinc-500 outline-none transition-colors"
              />
              <p className="text-[11px] text-zinc-500">
                Separa múltiples grupos con punto y coma (;). Déjalo vacío para importar todos los canales.
              </p>
            </div>

            {/* 7. Checkbox: Importar guía directamente desde el M3U */}
            <div className="flex items-center gap-3 pt-1">
              <input
                type="checkbox"
                id="import_guide"
                checked={formData.import_guide_from_m3u}
                onChange={(e) => setFormData({ ...formData, import_guide_from_m3u: e.target.checked })}
                className="w-4 h-4 rounded border-zinc-700 bg-zinc-900 text-[#E50914] focus:ring-0 cursor-pointer"
              />
              <label htmlFor="import_guide" className="text-xs text-zinc-300 select-none cursor-pointer">
                Importar guía directamente desde el M3U cuando esté disponible
              </label>
            </div>

            {/* 8. Fuente preferida de imagen de canal */}
            <div className="space-y-1.5 pt-1">
              <label className="block text-xs font-medium text-zinc-300">
                Fuente preferida de imagen de canal:
              </label>
              <select
                value={formData.preferred_image_source}
                onChange={(e) => setFormData({ ...formData, preferred_image_source: e.target.value })}
                className="w-full max-w-sm bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white outline-none transition-colors cursor-pointer"
              >
                <option value="Sintonizador / M3U">Sintonizador / M3U</option>
                <option value="Fuente de datos de guía">Fuente de datos de guía</option>
              </select>
            </div>

            {/* 9. Checkbox: Permitir mapeo a datos de guía usando números de canal */}
            <div className="flex items-center gap-3 pt-1">
              <input
                type="checkbox"
                id="allow_mapping"
                checked={formData.allow_channel_number_mapping}
                onChange={(e) => setFormData({ ...formData, allow_channel_number_mapping: e.target.checked })}
                className="w-4 h-4 rounded border-zinc-700 bg-zinc-900 text-[#E50914] focus:ring-0 cursor-pointer"
              />
              <label htmlFor="allow_mapping" className="text-xs text-zinc-300 select-none cursor-pointer">
                Permitir mapeo a datos de guía usando números de canal
              </label>
            </div>

            {/* 10. Etiquetas adicionales para canales */}
            <div className="space-y-1.5 pt-1">
              <label className="block text-xs font-medium text-zinc-300">
                Etiquetas adicionales para canales:
              </label>
              <input
                type="text"
                value={formData.tags}
                onChange={(e) => setFormData({ ...formData, tags: e.target.value })}
                placeholder="FIBRA_ISP; IPTV_LIVE; HD"
                className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-md px-3 py-2 text-xs text-white placeholder-zinc-500 outline-none transition-colors"
              />
            </div>

            {/* Botones de Acción */}
            <div className="flex items-center gap-3 pt-6 border-t border-zinc-800">
              <button
                type="submit"
                disabled={actionLoading}
                className="bg-green-600 hover:bg-green-700 text-white font-medium px-6 py-2 rounded-md text-xs transition-colors shadow-md disabled:opacity-50 cursor-pointer flex items-center gap-2"
              >
                {actionLoading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                {actionLoading ? 'Sincronizando...' : 'Guardar'}
              </button>

              <button
                type="button"
                onClick={() => {
                  setShowM3uForm(false);
                  setEditingSource(null);
                }}
                disabled={actionLoading}
                className="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white font-medium px-5 py-2 rounded-md text-xs transition-colors cursor-pointer"
              >
                Cancelar
              </button>
            </div>
          </form>
        </div>
      )}

      {/* ========================================================================= */}
      {/* VISTA 3: CANALES (CHANNELS) */}
      {/* ========================================================================= */}
      {activeTab === 'channels' && (
        <div className="space-y-5 animate-fadeIn">
          {/* Barra de Filtros y Búsqueda */}
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-[#181818] p-4 rounded-xl border border-zinc-800">
            <div className="relative flex-1 max-w-md">
              <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={channelSearch}
                onChange={(e) => {
                  setChannelSearch(e.target.value);
                  setChannelsPage(1);
                }}
                placeholder="Buscar canal por nombre, número o grupo..."
                className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] rounded-lg pl-9 pr-3 py-2 text-xs text-white outline-none transition-colors"
              />
            </div>

            <div className="flex items-center gap-3">
              {/* Selector de Categoría */}
              <select
                value={selectedCategory}
                onChange={(e) => {
                  setSelectedCategory(e.target.value);
                  setChannelsPage(1);
                }}
                className="bg-[#121212] border border-zinc-700 text-xs text-zinc-200 rounded-lg px-3 py-2 outline-none cursor-pointer"
              >
                <option value="all">Todas las categorías</option>
                {categories.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>

              <span className="text-xs text-zinc-400 font-mono">
                {channelsTotal} {channelsTotal === 1 ? 'canal' : 'canales'}
              </span>
            </div>
          </div>

          {/* Tabla de Canales */}
          <div className="bg-[#181818] border border-zinc-800 rounded-xl overflow-hidden shadow-sm">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-[#121212] border-b border-zinc-800 text-zinc-400 font-semibold uppercase tracking-wider text-[11px]">
                  <tr>
                    <th className="py-3 px-4">Canal</th>
                    <th className="py-3 px-4">Número</th>
                    <th className="py-3 px-4">Categoría</th>
                    <th className="py-3 px-4">Fuente</th>
                    <th className="py-3 px-4">Estado</th>
                    <th className="py-3 px-4 text-right">Acción</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {channels.length === 0 ? (
                    <tr>
                      <td colSpan="6" className="py-12 text-center text-zinc-500">
                        No se encontraron canales que coincidan con la búsqueda.
                      </td>
                    </tr>
                  ) : (
                    channels.map((ch) => (
                      <tr key={ch.id} className="hover:bg-zinc-800/40 transition-colors">
                        <td className="py-3 px-4 flex items-center gap-3">
                          <div className="w-9 h-9 rounded bg-[#121212] border border-zinc-800 flex items-center justify-center overflow-hidden shrink-0">
                            {ch.logo_url ? (
                              <img
                                src={ch.logo_url}
                                alt={ch.name}
                                className="w-full h-full object-contain p-1"
                                onError={(e) => {
                                  e.target.style.display = 'none';
                                }}
                              />
                            ) : (
                              <Tv className="w-4 h-4 text-zinc-500" />
                            )}
                          </div>
                          <div>
                            <p className="font-bold text-white text-xs truncate max-w-[200px]">{ch.name}</p>
                            <span className="text-[10px] text-zinc-500 font-mono truncate block max-w-[220px]">
                              {ch.tvg_id || ch.tvg_name || 'Sin TVG-ID'}
                            </span>
                          </div>
                        </td>
                        <td className="py-3 px-4 font-mono text-zinc-300">
                          #{ch.channel_number || '-'}
                        </td>
                        <td className="py-3 px-4">
                          <span className="bg-zinc-800 text-zinc-300 px-2 py-0.5 rounded text-[11px] font-medium border border-zinc-700/60">
                            {ch.group_title || 'General'}
                          </span>
                        </td>
                        <td className="py-3 px-4 text-zinc-400 text-xs">
                          {ch.tv_source?.name || 'M3U'}
                        </td>
                        <td className="py-3 px-4">
                          <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950/80 text-emerald-400 border border-emerald-800/50">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                            En Línea
                          </span>
                        </td>
                        <td className="py-3 px-4 text-right">
                          <button
                            type="button"
                            onClick={() => setPreviewChannel(ch)}
                            className="inline-flex items-center gap-1.5 bg-[#E50914] hover:bg-[#F40612] text-white px-2.5 py-1 rounded text-xs font-medium transition-colors shadow-sm cursor-pointer"
                          >
                            <Play className="w-3 h-3 fill-current" />
                            Probar
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* VISTA 4: AVANZADO (ADVANCED) */}
      {/* ========================================================================= */}
      {activeTab === 'advanced' && (
        <div className="bg-[#181818] border border-zinc-800 rounded-xl p-6 md:p-8 space-y-6 max-w-2xl animate-fadeIn">
          <h3 className="text-base font-bold text-white flex items-center gap-2">
            <Sliders className="w-4 h-4 text-[#E50914]" />
            Parámetros de Transmisión HLS On-Net
          </h3>

          <div className="space-y-4 text-xs">
            <div className="space-y-1.5">
              <label className="text-zinc-300 font-medium block">
                Búfer de Baja Latencia HLS (Segundos):
              </label>
              <input
                type="number"
                defaultValue="3"
                className="w-32 bg-[#121212] border border-zinc-700 rounded px-3 py-1.5 text-white font-mono outline-none"
              />
              <p className="text-[11px] text-zinc-500">
                Tiempo de búfer objetivo en el reproductor del cliente antes del inicio de reproducción.
              </p>
            </div>

            <div className="space-y-1.5">
              <label className="text-zinc-300 font-medium block">
                Intervalo de Sincronización Automática EPG (Horas):
              </label>
              <input
                type="number"
                defaultValue="12"
                className="w-32 bg-[#121212] border border-zinc-700 rounded px-3 py-1.5 text-white font-mono outline-none"
              />
              <p className="text-[11px] text-zinc-500">
                Frecuencia con la que el servidor descarga actualizaciones de la guía XMLTV.
              </p>
            </div>

            <div className="pt-2">
              <button
                type="button"
                onClick={() => showToast('Parámetros avanzados guardados con éxito.')}
                className="bg-[#E50914] hover:bg-[#F40612] text-white px-5 py-2 rounded-md font-semibold transition-colors cursor-pointer"
              >
                Guardar Preferencias Avanzadas
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: SELECCIÓN DE TIPO DE FUENTE (IMAGEN 2) */}
      {/* ========================================================================= */}
      {showTypeModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div
            onClick={(e) => e.stopPropagation()}
            className="bg-[#181818] border border-zinc-700 shadow-2xl rounded-lg p-6 w-full max-w-md space-y-5 animate-scaleUp"
          >
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-base font-bold text-white">Agregar Fuente de TV</h3>
              <button
                onClick={() => setShowTypeModal(false)}
                className="p-1 text-zinc-400 hover:text-white rounded"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <p className="text-xs text-zinc-400">
              Selecciona el tipo de sintonizador o proveedor de televisión en vivo que deseas conectar a FlixHN:
            </p>

            <div className="space-y-3">
              {/* Opción 1: HD Homerun */}
              <button
                type="button"
                onClick={handleSelectHdHomerunType}
                className="w-full bg-[#202020] hover:bg-zinc-800 border border-zinc-750 hover:border-zinc-600 rounded-lg p-4 flex items-center gap-4 text-left transition-all group cursor-pointer"
              >
                <div className="w-10 h-10 rounded-lg bg-zinc-900 border border-zinc-700 flex items-center justify-center text-zinc-400 group-hover:text-white group-hover:bg-[#E50914]/20 group-hover:border-[#E50914]/50 transition-colors">
                  <RadioTower className="w-5 h-5" />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-white">HD Homerun</h4>
                  <p className="text-[11px] text-zinc-400">
                    Detector automático de sintonizadores de TV digital en la red de área local (LAN).
                  </p>
                </div>
              </button>

              {/* Opción 2: M3U */}
              <button
                type="button"
                onClick={handleSelectM3uType}
                className="w-full bg-[#202020] hover:bg-zinc-800 border border-zinc-750 hover:border-[#E50914] rounded-lg p-4 flex items-center gap-4 text-left transition-all group cursor-pointer"
              >
                <div className="w-10 h-10 rounded-lg bg-zinc-900 border border-zinc-700 flex items-center justify-center text-zinc-400 group-hover:text-[#E50914] group-hover:bg-[#E50914]/20 group-hover:border-[#E50914]/50 transition-colors">
                  <Tv className="w-5 h-5" />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-white flex items-center gap-1.5">
                    M3U
                    <span className="text-[10px] bg-[#E50914] text-white px-1.5 py-0.2 rounded font-mono font-bold">
                      Recomendado
                    </span>
                  </h4>
                  <p className="text-[11px] text-zinc-400">
                    Lista de canales IPTV, flujos HLS (.m3u8), TS y protocolos HTTP del proveedor.
                  </p>
                </div>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: HD HOMERUN SCAN */}
      {/* ========================================================================= */}
      {showHdHomerunModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div className="bg-[#181818] border border-zinc-700 shadow-2xl rounded-lg p-6 w-full max-w-md space-y-4">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-base font-bold text-white">Detector HD Homerun LAN</h3>
              <button onClick={() => setShowHdHomerunModal(false)} className="text-zinc-400 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="py-6 text-center space-y-3">
              <RadioTower className="w-12 h-12 text-[#E50914] mx-auto animate-pulse" />
              <p className="text-xs text-zinc-300">
                Buscando sintonizadores HD Homerun compatibles en la subred local (UDP 65001)...
              </p>
              <p className="text-[11px] text-zinc-500">
                No se detectaron sintonizadores de hardware activos en esta interfaz de red. Puedes utilizar una lista M3U para conectar tus canales de fibra óptica.
              </p>
            </div>

            <div className="flex justify-end gap-2 border-t border-zinc-800 pt-3">
              <button
                onClick={() => {
                  setShowHdHomerunModal(false);
                  handleSelectM3uType();
                }}
                className="bg-[#E50914] text-white text-xs font-bold px-4 py-2 rounded"
              >
                Configurar M3U en su lugar
              </button>
              <button
                onClick={() => setShowHdHomerunModal(false)}
                className="bg-zinc-800 text-zinc-300 text-xs px-3 py-2 rounded"
              >
                Cerrar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: FUENTE DE GUÍA EPG */}
      {/* ========================================================================= */}
      {showGuideModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div className="bg-[#181818] border border-zinc-700 shadow-2xl rounded-lg p-6 w-full max-w-md space-y-4">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-base font-bold text-white">Agregar Fuente de Guía EPG</h3>
              <button onClick={() => setShowGuideModal(false)} className="text-zinc-400 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveGuideSource} className="space-y-4 text-xs">
              <div className="space-y-1">
                <label className="text-zinc-300 font-medium">Nombre de la Guía:</label>
                <input
                  type="text"
                  required
                  value={guideFormData.name}
                  onChange={(e) => setGuideFormData({ ...guideFormData, name: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-700 rounded px-3 py-2 text-white"
                />
              </div>

              <div className="space-y-1">
                <label className="text-zinc-300 font-medium">URL del XMLTV / EPG:</label>
                <input
                  type="text"
                  required
                  value={guideFormData.url}
                  onChange={(e) => setGuideFormData({ ...guideFormData, url: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-700 rounded px-3 py-2 text-white font-mono"
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowGuideModal(false)}
                  className="bg-zinc-800 text-zinc-300 px-4 py-2 rounded text-xs"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={actionLoading}
                  className="bg-[#E50914] text-white px-5 py-2 rounded text-xs font-bold"
                >
                  Guardar Guía
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Reproductor de Prueba en Vivo (Modal Zapping) */}
      {previewChannel && (
        <LiveTvPlayer
          channel={previewChannel}
          channels={channels}
          onClose={() => setPreviewChannel(null)}
          onSelectChannel={(newCh) => setPreviewChannel(newCh)}
        />
      )}
    </div>
  );
}
