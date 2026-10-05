import React, { useState, useEffect, useCallback } from 'react';
import {
  Tv,
  Plus,
  RefreshCw,
  Edit2,
  Pencil,
  Trash2,
  Search,
  CheckCircle2,
  AlertTriangle,
  Play,
  Database,
  Sliders,
  X,
  RadioTower,
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
  const [isSourceModalOpen, setIsSourceModalOpen] = useState(false);
  const [showHdHomerunModal, setShowHdHomerunModal] = useState(false);
  const [showGuideModal, setShowGuideModal] = useState(false);
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

  // Abrir modal para crear fuente
  const handleOpenAddSource = () => {
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
    setIsSourceModalOpen(true);
  };

  // Abrir modal para editar fuente (precargando datos)
  const handleEditSource = (source, e) => {
    if (e) e.stopPropagation();
    setEditingSource(source);
    setFormData({
      name: source.name || 'M3U',
      type: source.type || 'm3u',
      url: source.url || '',
      user_agent: source.user_agent || '',
      referer_mode: source.referer_mode || 'Ninguno',
      referrer_header: source.referrer_header || '',
      stream_limit: source.stream_limit ?? 0,
      group_filter: source.group_filter || '',
      import_guide_from_m3u: source.import_guide_from_m3u ?? true,
      preferred_image_source: source.preferred_image_source || 'Sintonizador / M3U',
      allow_channel_number_mapping: source.allow_channel_number_mapping ?? false,
      tags: source.tags || '',
    });
    setIsSourceModalOpen(true);
  };

  // Guardar fuente M3U (Creación o Edición)
  const handleSaveM3u = async (e) => {
    if (e) e.preventDefault();
    setActionLoading(true);
    try {
      if (editingSource) {
        const res = await api.put(`/admin/tv-sources/${editingSource.id}`, formData);
        const count = res.data?.total_synced ?? res.data?.sync?.total_synced ?? res.data?.count;
        if (count !== undefined) {
          showToast(`Fuente actualizada. Se sincronizaron ${count} canales.`);
        } else {
          showToast(res.data?.message || 'Fuente de TV actualizada con éxito.');
        }
      } else {
        const res = await api.post('/admin/tv-sources', formData);
        const count = res.data?.total_synced ?? res.data?.sync?.total_synced ?? res.data?.count;
        if (count !== undefined) {
          showToast(`Fuente guardada. Se sincronizaron ${count} canales.`);
        } else {
          showToast(res.data?.message || 'Fuente de TV guardada y sincronizada.');
        }
      }
      setIsSourceModalOpen(false);
      setEditingSource(null);
      await loadSources();
      if (activeTab === 'channels') loadChannels();
    } catch (err) {
      console.error('Error guardando fuente M3U:', err);
      showToast(err.response?.data?.error || err.response?.data?.message || 'Error al guardar la fuente de TV.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Sincronizar fuente M3U
  const handleSyncSource = async (sourceId, e) => {
    if (e) e.stopPropagation();
    setActionLoading(true);
    try {
      const res = await api.post(`/admin/tv-sources/${sourceId}/refresh`);
      const total = res.data?.total_synced ?? res.data?.sync?.total_synced ?? res.data?.count;
      if (total !== undefined) {
        showToast(`Se sincronizaron ${total} canales en la base de datos.`);
      } else {
        showToast(res.data?.message || 'Canales sincronizados correctamente.');
      }
      await loadSources();
      if (activeTab === 'channels') loadChannels();
    } catch (err) {
      console.error('Error sincronizando canales:', err);
      showToast(err.response?.data?.error || err.response?.data?.message || 'Error al conectar con la fuente IPTV.', 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // Eliminar fuente de TV
  const handleDeleteSource = async (sourceId, e) => {
    if (e) e.stopPropagation();
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
      let totalSynced = 0;
      let hasSources = false;
      if (sources.length > 0) {
        hasSources = true;
        const results = await Promise.all(
          sources.map((s) => api.post(`/admin/tv-sources/${s.id}/refresh`))
        );
        results.forEach((r) => {
          const count = r.data?.total_synced ?? r.data?.sync?.total_synced ?? r.data?.count ?? 0;
          totalSynced += count;
        });
      }
      if (guideSources.length > 0) {
        await Promise.all(
          guideSources.map((g) => api.post(`/admin/tv-guide-sources/${g.id}/refresh`))
        );
        await loadGuideSources();
      }
      await loadSources();
      if (activeTab === 'channels') loadChannels();

      if (hasSources) {
        showToast(`Se sincronizaron ${totalSynced} canales en la base de datos.`);
      } else {
        showToast('Datos de la guía electrónica de programas (EPG) actualizados.');
      }
    } catch (err) {
      console.error('Error actualizando guía EPG:', err);
      showToast(err.response?.data?.error || err.response?.data?.message || 'Guía actualizada con los datos disponibles.', 'info');
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
    <div className="bg-[#141414] text-white min-h-screen -m-6 md:-m-8 p-6 md:p-8 space-y-6 animate-fadeIn">
      {/* Notificación Toast Flotante */}
      {notification && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-lg shadow-2xl border transition-all duration-300 animate-slideUp ${
            notification.type === 'error'
              ? 'bg-[#181818] border-red-700 text-red-200'
              : notification.type === 'info'
              ? 'bg-[#181818] border-blue-700 text-blue-200'
              : 'bg-[#181818] border-emerald-600 text-emerald-200'
          }`}
        >
          {notification.type === 'error' ? (
            <AlertTriangle className="w-5 h-5 text-red-400 shrink-0" />
          ) : (
            <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          )}
          <span className="text-xs font-semibold">{notification.message}</span>
          <button
            onClick={() => setNotification(null)}
            className="p-1 hover:text-white rounded ml-2"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Cabecera Principal de la Sección */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl md:text-2xl font-bold text-white tracking-wide flex items-center gap-2">
            <Tv className="w-6 h-6 text-[#E50914]" />
            TV en Vivo (Live TV)
          </h1>
          <p className="text-xs text-zinc-400 mt-1">
            Gestión de sintonizadores, fuentes IPTV M3U/M3U8 y datos de guía de programación (EPG).
          </p>
        </div>
      </div>

      {/* Pestañas Superiores (Setup, Channels, Advanced) */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-3">
        <button
          type="button"
          onClick={() => setActiveTab('setup')}
          className={`px-4 py-1.5 rounded-md text-xs transition-colors cursor-pointer ${
            activeTab === 'setup'
              ? 'bg-[#E50914] text-white font-semibold'
              : 'text-zinc-400 hover:text-white'
          }`}
        >
          Setup
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('channels')}
          className={`px-4 py-1.5 rounded-md text-xs transition-colors cursor-pointer ${
            activeTab === 'channels'
              ? 'bg-[#E50914] text-white font-semibold'
              : 'text-zinc-400 hover:text-white'
          }`}
        >
          Channels
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('advanced')}
          className={`px-4 py-1.5 rounded-md text-xs transition-colors cursor-pointer ${
            activeTab === 'advanced'
              ? 'bg-[#E50914] text-white font-semibold'
              : 'text-zinc-400 hover:text-white'
          }`}
        >
          Advanced
        </button>
      </div>

      {/* ========================================================================= */}
      {/* PESTAÑA 1: SETUP (CONFIGURACIÓN) */}
      {/* ========================================================================= */}
      {activeTab === 'setup' && (
        <div className="space-y-8 animate-fadeIn">
          {/* SECCIÓN DE TV SOURCES */}
          <section className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-bold text-white flex items-center gap-2">
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
                  className="bg-[#E50914] hover:bg-[#b80710] text-white text-xs font-semibold px-4 py-2 rounded-lg flex items-center gap-2 transition-colors cursor-pointer shadow-md active:scale-95"
                >
                  <Plus className="w-4 h-4" />
                  + Agregar Fuente de TV
                </button>

                <button
                  type="button"
                  onClick={handleRefreshGuide}
                  disabled={refreshingGuide}
                  className="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold px-4 py-2 rounded-lg flex items-center gap-2 transition-colors cursor-pointer disabled:opacity-60"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${refreshingGuide ? 'animate-spin text-[#E50914]' : ''}`} />
                  Actualizar Datos de Guía
                </button>
              </div>
            </div>

            {/* Grid de Tarjetas de Fuentes (Sources) */}
            {loading ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {[1, 2].map((i) => (
                  <div key={i} className="h-48 bg-[#181818] border border-zinc-800 rounded-lg animate-pulse" />
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
                  type="button"
                  onClick={handleOpenAddSource}
                  className="inline-flex items-center gap-2 bg-[#E50914] hover:bg-[#b80710] text-white px-4 py-2 rounded-lg text-xs font-semibold shadow-md transition-colors cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  + Agregar Fuente de TV
                </button>
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {sources.map((src) => (
                  <div
                    key={src.id}
                    className="bg-[#181818] border border-zinc-800 rounded-lg p-5 flex flex-col justify-between hover:border-zinc-700 transition-all shadow-md max-w-sm group"
                  >
                    <div>
                      {/* Cabecera de la tarjeta: Badge y Acciones */}
                      <div className="flex items-center justify-between gap-2 mb-3">
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 rounded text-[10px] font-mono uppercase font-bold bg-[#E50914]/20 text-[#E50914] border border-[#E50914]/30">
                            {src.type || 'M3U'}
                          </span>
                          <span className="text-[10px] font-mono bg-zinc-900 text-zinc-400 px-1.5 py-0.5 rounded border border-zinc-800">
                            {src.tv_channels_count || src.channels_count || 0} CANALES
                          </span>
                        </div>

                        {/* Botones de acción rápida: Sincronizar, Editar (Pencil) y Eliminar (Trash2) */}
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            onClick={(e) => handleSyncSource(src.id, e)}
                            title="Sincronizar Canales"
                            className="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded transition-colors cursor-pointer"
                          >
                            <RefreshCw className="w-3.5 h-3.5" />
                          </button>
                          <button
                            type="button"
                            onClick={(e) => handleEditSource(src, e)}
                            title="Editar Fuente"
                            className="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded transition-colors cursor-pointer"
                          >
                            <Pencil className="w-3.5 h-3.5" />
                          </button>
                          <button
                            type="button"
                            onClick={(e) => handleDeleteSource(src.id, e)}
                            title="Eliminar Fuente"
                            className="p-1.5 text-zinc-400 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors cursor-pointer"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </div>

                      {/* Área central de vista previa: contenedor oscuro */}
                      <div className="bg-zinc-900/60 rounded-md p-6 flex items-center justify-center text-zinc-500 group-hover:text-zinc-400 transition-colors">
                        <Tv className="w-10 h-10 stroke-[1.5] group-hover:scale-110 group-hover:text-[#E50914] transition-all" />
                      </div>

                      {/* Título de la fuente */}
                      <h3 className="text-sm font-bold text-white mt-3 truncate" title={src.name || 'M3U'}>
                        {src.name || 'M3U'}
                      </h3>

                      {/* URL de la fuente */}
                      <p className="text-xs font-mono text-zinc-500 truncate mt-1" title={src.url}>
                        {src.url}
                      </p>
                    </div>

                    {/* Footer de la tarjeta con estado y fecha */}
                    <div className="flex items-center justify-between text-[11px] text-zinc-500 pt-3 border-t border-zinc-800 mt-4">
                      <span className="flex items-center gap-1.5 text-zinc-400">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                        Activa
                      </span>
                      <span className="text-[10px] font-mono text-zinc-500">
                        {src.last_synced_at
                          ? new Date(src.last_synced_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                          : 'Pendiente'}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>

          {/* SECCIÓN DE FUENTES DE GUÍA (EPG) */}
          <section className="space-y-4 pt-6 border-t border-zinc-800">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-bold text-white flex items-center gap-2">
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
                className="flex items-center gap-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold px-4 py-2 rounded-lg border border-zinc-700 transition-colors shadow-sm cursor-pointer"
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
                    className="bg-[#181818] border border-zinc-800 rounded-lg p-4 flex items-center justify-between gap-4 hover:border-zinc-700 transition-all"
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
                        <p className="text-[11px] text-zinc-400 font-mono truncate mt-0.5" title={guide.url}>
                          {guide.url}
                        </p>
                      </div>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                      <button
                        onClick={() => handleDeleteGuideSource(guide.id)}
                        className="p-1.5 text-zinc-400 hover:text-red-400 rounded hover:bg-zinc-800 transition-colors cursor-pointer"
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
      {/* PESTAÑA 2: CHANNELS (CANALES) */}
      {/* ========================================================================= */}
      {activeTab === 'channels' && (
        <div className="space-y-5 animate-fadeIn">
          {/* Barra de Filtros y Búsqueda */}
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-[#181818] p-4 rounded-xl border border-zinc-800 shadow-sm">
            <div className="relative flex-1 max-w-md">
              <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                type="text"
                value={channelSearch}
                onChange={(e) => {
                  setChannelSearch(e.target.value);
                  setChannelsPage(1);
                }}
                placeholder="Buscar canal por nombre, número o grupo..."
                className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg pl-9 pr-3 py-2 text-xs text-white outline-none transition-colors"
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
                className="bg-[#121212] border border-zinc-700 focus:border-red-600 text-xs text-zinc-200 rounded-lg px-3 py-2 outline-none cursor-pointer"
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
                          <span className="bg-zinc-900 text-zinc-300 px-2 py-0.5 rounded text-[11px] font-medium border border-zinc-800">
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
                            className="inline-flex items-center gap-1.5 bg-[#E50914] hover:bg-[#b80710] text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors shadow-sm cursor-pointer"
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
      {/* PESTAÑA 3: ADVANCED (AVANZADO) */}
      {/* ========================================================================= */}
      {activeTab === 'advanced' && (
        <div className="bg-[#181818] border border-zinc-800 rounded-xl p-6 md:p-8 space-y-6 max-w-2xl text-white shadow-sm animate-fadeIn">
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
                className="w-32 bg-[#121212] border border-zinc-700 focus:border-red-600 rounded px-3 py-1.5 text-white font-mono outline-none transition-colors"
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
                className="w-32 bg-[#121212] border border-zinc-700 focus:border-red-600 rounded px-3 py-1.5 text-white font-mono outline-none transition-colors"
              />
              <p className="text-[11px] text-zinc-500">
                Frecuencia con la que el servidor descarga actualizaciones de la guía XMLTV.
              </p>
            </div>

            <div className="pt-2">
              <button
                type="button"
                onClick={() => showToast('Parámetros avanzados guardados con éxito.')}
                className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-5 py-2.5 rounded-lg transition-colors cursor-pointer"
              >
                Guardar Preferencias Avanzadas
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL FLOTANTE: CONFIGURACIÓN DE FUENTE DE TV (M3U) - AGREGAR / EDITAR */}
      {/* ========================================================================= */}
      {isSourceModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div
            onClick={(e) => e.stopPropagation()}
            className="bg-[#181818] border border-zinc-800 w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl p-6 md:p-8 shadow-2xl space-y-5 text-white"
          >
            {/* Cabecera del modal */}
            <div className="flex items-center justify-between border-b border-zinc-800 pb-4">
              <div>
                <h3 className="text-base md:text-lg font-bold text-white flex items-center gap-2">
                  <Tv className="w-5 h-5 text-[#E50914]" />
                  Configuración de Fuente de TV (M3U)
                </h3>
                <p className="text-xs text-zinc-400 mt-1">
                  {editingSource
                    ? `Modificando parámetros de la fuente: ${editingSource.name || 'M3U'}`
                    : 'Agrega una nueva lista IPTV M3U/M3U8 para la red de fibra óptica.'}
                </p>
              </div>
              <button
                type="button"
                onClick={() => {
                  setIsSourceModalOpen(false);
                  setEditingSource(null);
                }}
                className="p-1.5 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
                title="Cerrar modal"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Formulario dentro del modal */}
            <form onSubmit={handleSaveM3u} className="space-y-4 text-xs">
              {/* Nombre de la fuente */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Nombre de la Fuente
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="M3U Principal"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors"
                />
                <p className="text-[10px] text-zinc-500 mt-1">Identificador visible de esta fuente en el panel.</p>
              </div>

              {/* 1. Archivo o URL (File or URL) */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Archivo o URL (File or URL) <span className="text-red-500">*</span>
                </label>
                <div className="relative">
                  <input
                    type="text"
                    required
                    value={formData.url}
                    onChange={(e) => setFormData({ ...formData, url: e.target.value })}
                    placeholder="http://proveedor-iptv:puerto/get.php?username=...&password=..."
                    className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors font-mono pr-9"
                  />
                  <Search className="w-4 h-4 text-zinc-500 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                </div>
                <p className="text-[10px] text-zinc-500 mt-1">Introduce la URL HLS completa del proveedor o ruta al archivo M3U local.</p>
              </div>

              {/* 2. User-Agent HTTP Header */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  User-Agent HTTP Header
                </label>
                <input
                  type="text"
                  value={formData.user_agent}
                  onChange={(e) => setFormData({ ...formData, user_agent: e.target.value })}
                  placeholder="FlixHN/2.0 (IPTV Sintonizador; HLS Client)"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors font-mono"
                />
                <p className="text-[10px] text-zinc-500 mt-1">Cadena opcional de agente de usuario para peticiones HTTP al proveedor.</p>
              </div>

              {/* 3 & 4. Referer Header Mode & Referrer HTTP Header */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                    Referer Header Mode
                  </label>
                  <select
                    value={formData.referer_mode}
                    onChange={(e) => setFormData({ ...formData, referer_mode: e.target.value })}
                    className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors cursor-pointer"
                  >
                    <option value="Ninguno">Ninguno</option>
                    <option value="Referer">Referer</option>
                    <option value="Referrer">Referrer</option>
                    <option value="Ambos">Ambos</option>
                  </select>
                  <p className="text-[10px] text-zinc-500 mt-1">Modo de inclusión del encabezado HTTP Referer.</p>
                </div>

                <div>
                  <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                    Referrer HTTP Header
                  </label>
                  <input
                    type="text"
                    value={formData.referrer_header}
                    onChange={(e) => setFormData({ ...formData, referrer_header: e.target.value })}
                    placeholder="https://proveedor.iptv"
                    className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors font-mono"
                  />
                  <p className="text-[10px] text-zinc-500 mt-1">Valor exacto del encabezado Referrer a enviar.</p>
                </div>
              </div>

              {/* 5. Límite de transmisiones simultáneas */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Límite de transmisiones simultáneas (Simultaneous stream limit)
                </label>
                <div className="flex items-center gap-3">
                  <input
                    type="number"
                    min="0"
                    value={formData.stream_limit}
                    onChange={(e) => setFormData({ ...formData, stream_limit: parseInt(e.target.value, 10) || 0 })}
                    className="w-32 bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors font-mono"
                  />
                  <span className="text-[11px] text-zinc-400">0 para sin límite</span>
                </div>
                <p className="text-[10px] text-zinc-500 mt-1">Límite de conexiones simultáneas permitidas para esta fuente IPTV.</p>
              </div>

              {/* 6. Filtrar por grupos de canales */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Filtrar por grupos de canales (Only import channels containing these groups)
                </label>
                <input
                  type="text"
                  value={formData.group_filter}
                  onChange={(e) => setFormData({ ...formData, group_filter: e.target.value })}
                  placeholder="Deportes; Noticias; Cine; Honduras"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors"
                />
                <p className="text-[10px] text-zinc-500 mt-1">Separa múltiples grupos con punto y coma (;). Déjalo vacío para importar todos los canales.</p>
              </div>

              {/* 7. Checkbox estilizado: Importar guía directamente desde el M3U */}
              <div className="pt-1">
                <label className="flex items-center gap-3 p-3 rounded-lg bg-[#121212] border border-zinc-800 hover:border-zinc-700 transition-colors cursor-pointer select-none">
                  <input
                    type="checkbox"
                    id="modal_import_guide"
                    checked={formData.import_guide_from_m3u}
                    onChange={(e) => setFormData({ ...formData, import_guide_from_m3u: e.target.checked })}
                    className="w-4 h-4 rounded border-zinc-700 bg-zinc-900 text-[#E50914] focus:ring-0 cursor-pointer accent-[#E50914]"
                  />
                  <div>
                    <span className="text-xs font-semibold text-zinc-200">Importar guía directamente desde el M3U</span>
                    <p className="text-[10px] text-zinc-500">Mapear automáticamente la información EPG integrada en los atributos tvg-* del M3U.</p>
                  </div>
                </label>
              </div>

              {/* 8. Preferencia de imágenes de canales */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Preferencia de imágenes de canales
                </label>
                <select
                  value={formData.preferred_image_source}
                  onChange={(e) => setFormData({ ...formData, preferred_image_source: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors cursor-pointer"
                >
                  <option value="Sintonizador / M3U">Sintonizador / M3U</option>
                  <option value="Fuente de datos de guía">Fuente de datos de guía</option>
                </select>
                <p className="text-[10px] text-zinc-500 mt-1">Prioridad del logotipo de canal entre el archivo M3U y la fuente de guía XMLTV.</p>
              </div>

              {/* 9. Tags adicionales para canales */}
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Tags adicionales para canales
                </label>
                <input
                  type="text"
                  value={formData.tags}
                  onChange={(e) => setFormData({ ...formData, tags: e.target.value })}
                  placeholder="FIBRA_ISP; IPTV_LIVE; HD"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors"
                />
                <p className="text-[10px] text-zinc-500 mt-1">Etiquetas opcionales separadas por punto y coma (;) aplicadas a todos los canales de esta fuente.</p>
              </div>

              {/* Pie del modal (Acciones) */}
              <div className="flex items-center justify-end gap-3 pt-5 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => {
                    setIsSourceModalOpen(false);
                    setEditingSource(null);
                  }}
                  disabled={actionLoading}
                  className="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-semibold text-xs px-4 py-2.5 rounded-lg transition-colors cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={actionLoading}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-5 py-2.5 rounded-lg transition-colors cursor-pointer flex items-center gap-2 shadow-lg disabled:opacity-50"
                >
                  {actionLoading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  Guardar Cambios
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: FUENTE DE GUÍA EPG */}
      {/* ========================================================================= */}
      {showGuideModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fadeIn">
          <div
            onClick={(e) => e.stopPropagation()}
            className="bg-[#181818] border border-zinc-800 shadow-2xl rounded-xl p-6 md:p-8 w-full max-w-md space-y-5 text-white"
          >
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-base font-bold text-white flex items-center gap-2">
                <Database className="w-5 h-5 text-indigo-400" />
                Agregar Fuente de Guía EPG
              </h3>
              <button
                onClick={() => setShowGuideModal(false)}
                className="p-1.5 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveGuideSource} className="space-y-4 text-xs">
              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  Nombre de la Guía
                </label>
                <input
                  type="text"
                  required
                  value={guideFormData.name}
                  onChange={(e) => setGuideFormData({ ...guideFormData, name: e.target.value })}
                  placeholder="Guía XMLTV Nacional"
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white outline-none transition-colors"
                />
              </div>

              <div>
                <label className="text-[11px] font-semibold text-zinc-300 block mb-1 uppercase tracking-wider">
                  URL del XMLTV / EPG
                </label>
                <input
                  type="text"
                  required
                  value={guideFormData.url}
                  onChange={(e) => setGuideFormData({ ...guideFormData, url: e.target.value })}
                  placeholder="http://proveedor-iptv:puerto/xmltv.php?..."
                  className="w-full bg-[#121212] border border-zinc-700 focus:border-red-600 rounded-lg px-3 py-2 text-xs text-white font-mono outline-none transition-colors"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowGuideModal(false)}
                  disabled={actionLoading}
                  className="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-semibold text-xs px-4 py-2.5 rounded-lg transition-colors cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={actionLoading}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-5 py-2.5 rounded-lg transition-colors cursor-pointer flex items-center gap-2 shadow-lg"
                >
                  {actionLoading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
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
