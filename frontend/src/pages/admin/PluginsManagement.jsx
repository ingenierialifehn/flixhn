import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  Puzzle,
  RefreshCw,
  Search,
  Filter,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  X,
  Settings,
  Trash2,
  Download,
  Power,
  Sliders,
  Check,
  Eye,
  EyeOff,
  Sparkles,
  Info,
  Layers,
  Activity,
  Cpu,
  Server,
  ArrowRight,
  ExternalLink,
  Film,
  Tv,
  Star,
  Palette,
  FileText,
  Building2,
  Music,
  Disc,
  Tv2,
  ListOrdered,
  Radio,
  Subtitles,
  Clapperboard,
  FolderGit2,
  Webhook,
  Network,
  Cast,
  Archive,
  BarChart3,
  Loader2,
  Save,
  HelpCircle,
  ShieldCheck,
} from 'lucide-react';

// Mapeo dinámico de íconos según icon_name o categoría
const ICON_MAP = {
  Film,
  Tv,
  Star,
  Palette,
  FileText,
  Building2,
  Music,
  Disc,
  Tv2,
  ListOrdered,
  Radio,
  Subtitles,
  Clapperboard,
  FolderGit2,
  Webhook,
  Network,
  Cast,
  Archive,
  BarChart3,
  Puzzle,
  Cpu,
  Server,
};

// Configuración visual por categoría (badges y acentos cromáticos FlixHN)
const CATEGORY_THEMES = {
  metadata: {
    label: 'Metadatos & Arte',
    badge: 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
    iconBg: 'bg-indigo-500/10 border-indigo-500/30 text-indigo-400',
    borderHover: 'hover:border-indigo-500/40',
  },
  livetv: {
    label: 'TV en Vivo & EPG',
    badge: 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20',
    iconBg: 'bg-cyan-500/10 border-cyan-500/30 text-cyan-400',
    borderHover: 'hover:border-cyan-500/40',
  },
  subtitles: {
    label: 'Subtítulos & Video',
    badge: 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
    iconBg: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
    borderHover: 'hover:border-amber-500/40',
  },
  network: {
    label: 'Red & Enlaces',
    badge: 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
    iconBg: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400',
    borderHover: 'hover:border-emerald-500/40',
  },
  system: {
    label: 'Sistema & Diagnóstico',
    badge: 'bg-rose-500/10 text-rose-400 border border-rose-500/20',
    iconBg: 'bg-rose-500/10 border-rose-500/30 text-rose-400',
    borderHover: 'hover:border-rose-500/40',
  },
};

export default function PluginsManagement() {
  // Pestañas principales: 'installed' (Mis Plugins) o 'catalog' (Catálogo)
  const [activeTab, setActiveTab] = useState('installed');

  // Estados de datos
  const [plugins, setPlugins] = useState([]);
  const [categoriesMeta, setCategoriesMeta] = useState({});
  const [summary, setSummary] = useState({
    total_installed: 0,
    total_enabled: 0,
    total_disabled: 0,
    total_catalog: 0,
  });

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filtros de búsqueda
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all'); // 'all', 'enabled', 'disabled' (solo para mis plugins)

  // Acciones en curso
  const [actionLoadingSlugs, setActionLoadingSlugs] = useState(new Set());

  // Sistema de Notificaciones Toast Flotantes (Cero alertas nativas)
  const [toast, setToast] = useState(null); // { type: 'success'|'error'|'info', title: string, message: string }

  const showToast = useCallback((type, message, title = '') => {
    setToast({ type, message, title });
  }, []);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToast(null), 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Modal de Configuración
  const [settingsModal, setSettingsModal] = useState({
    open: false,
    plugin: null,
    formValues: {},
    saving: false,
    showPassword: {},
  });

  // Modal de Confirmación de Desinstalación
  const [uninstallModal, setUninstallModal] = useState({
    open: false,
    plugin: null,
    processing: false,
  });

  // Carga de plugins desde la API
  const fetchPlugins = useCallback(async (isSilent = false) => {
    if (!isSilent) setRefreshing(true);
    try {
      // Cargar catálogo completo para tener consistencia de resumen
      const res = await api.get('/admin/plugins/catalog');
      if (res.data?.status === 'success' && res.data?.data) {
        setPlugins(res.data.data.plugins || []);
        setCategoriesMeta(res.data.data.categories || {});
        setSummary(res.data.data.summary || {});
      }
    } catch (err) {
      console.error('Error cargando plugins FlixHN:', err);
      showToast('error', 'No se pudieron recuperar los complementos del servidor.', 'Error de Conexión');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [showToast]);

  useEffect(() => {
    fetchPlugins();
  }, [fetchPlugins]);

  // Toggle Activo/Pausa
  const handleToggle = async (plugin) => {
    const slug = plugin.slug;
    setActionLoadingSlugs((prev) => new Set(prev).add(slug));

    // Optimista
    const nextState = !plugin.is_enabled;
    setPlugins((prev) =>
      prev.map((p) => (p.slug === slug ? { ...p, is_enabled: nextState } : p))
    );

    try {
      const res = await api.post(`/admin/plugins/${slug}/toggle`, { is_enabled: nextState });
      if (res.data?.status === 'success') {
        showToast(
          'success',
          res.data.message || `Plugin ${plugin.name} ${nextState ? 'activado' : 'desactivado'}.`,
          nextState ? 'Plugin Activo' : 'Plugin en Pausa'
        );
      }
    } catch (err) {
      // Revertir
      setPlugins((prev) =>
        prev.map((p) => (p.slug === slug ? { ...p, is_enabled: plugin.is_enabled } : p))
      );
      const errMsg = err.response?.data?.message || 'Error al cambiar estado del plugin';
      showToast('error', errMsg, 'Error');
    } finally {
      setActionLoadingSlugs((prev) => {
        const next = new Set(prev);
        next.delete(slug);
        return next;
      });
      fetchPlugins(true);
    }
  };

  // Instalar Plugin desde el Catálogo
  const handleInstall = async (plugin) => {
    const slug = plugin.slug;
    setActionLoadingSlugs((prev) => new Set(prev).add(slug));

    try {
      const res = await api.post(`/admin/plugins/${slug}/install`);
      if (res.data?.status === 'success') {
        showToast(
          'success',
          `El complemento "${plugin.name}" se instaló correctamente y está listo para ser configurado.`,
          'Instalación Completada'
        );
        // Actualizar en memoria
        setPlugins((prev) =>
          prev.map((p) => (p.slug === slug ? { ...p, is_installed: true, is_enabled: true } : p))
        );
        fetchPlugins(true);
      } else {
        showToast('error', res.data?.message || 'No se pudo instalar el plugin.', 'Error de Instalación');
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || 'Error durante la instalación';
      showToast('error', errMsg, 'Fallo de Instalación');
    } finally {
      setActionLoadingSlugs((prev) => {
        const next = new Set(prev);
        next.delete(slug);
        return next;
      });
    }
  };

  // Confirmar y Ejecutar Desinstalación
  const handleConfirmUninstall = async () => {
    const plugin = uninstallModal.plugin;
    if (!plugin) return;

    setUninstallModal((prev) => ({ ...prev, processing: true }));

    try {
      const res = await api.post(`/admin/plugins/${plugin.slug}/uninstall`);
      if (res.data?.status === 'success') {
        showToast(
          'info',
          `El complemento "${plugin.name}" ha sido desinstalado y sus configuraciones locales fueron restablecidas.`,
          'Plugin Desinstalado'
        );
        setPlugins((prev) =>
          prev.map((p) => (p.slug === plugin.slug ? { ...p, is_installed: false, is_enabled: false } : p))
        );
        setUninstallModal({ open: false, plugin: null, processing: false });
        fetchPlugins(true);
      } else {
        showToast('error', res.data?.message || 'Error al desinstalar el plugin', 'Error');
        setUninstallModal((prev) => ({ ...prev, processing: false }));
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || 'Error al desinstalar el plugin';
      showToast('error', errMsg, 'Fallo al Desinstalar');
      setUninstallModal((prev) => ({ ...prev, processing: false }));
    }
  };

  // Abrir Modal de Configuración
  const handleOpenSettings = async (plugin) => {
    try {
      const res = await api.get(`/admin/plugins/${plugin.slug}/settings`);
      const currentSettings = res.data?.data?.settings || plugin.settings || plugin.default_settings || {};
      setSettingsModal({
        open: true,
        plugin,
        formValues: { ...currentSettings },
        saving: false,
        showPassword: {},
      });
    } catch (err) {
      // Fallback a los valores en el estado local
      setSettingsModal({
        open: true,
        plugin,
        formValues: { ...(plugin.settings || plugin.default_settings || {}) },
        saving: false,
        showPassword: {},
      });
    }
  };

  // Guardar Configuración
  const handleSaveSettings = async () => {
    const plugin = settingsModal.plugin;
    if (!plugin) return;

    setSettingsModal((prev) => ({ ...prev, saving: true }));

    try {
      const res = await api.put(`/admin/plugins/${plugin.slug}/settings`, {
        settings: settingsModal.formValues,
      });

      if (res.data?.status === 'success') {
        showToast(
          'success',
          `La configuración para "${plugin.name}" se guardó correctamente en el nodo central.`,
          'Ajustes Guardados'
        );
        setPlugins((prev) =>
          prev.map((p) =>
            p.slug === plugin.slug ? { ...p, settings: settingsModal.formValues } : p
          )
        );
        setSettingsModal({ open: false, plugin: null, formValues: {}, saving: false, showPassword: {} });
        fetchPlugins(true);
      } else {
        showToast('error', res.data?.message || 'Error al guardar configuración', 'Error');
        setSettingsModal((prev) => ({ ...prev, saving: false }));
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || 'Error al guardar la configuración';
      showToast('error', errMsg, 'Fallo de Guardado');
      setSettingsModal((prev) => ({ ...prev, saving: false }));
    }
  };

  // Filtrado reactivo de plugins según pestaña activa, buscador y categoría
  const displayedPlugins = useMemo(() => {
    return plugins.filter((plugin) => {
      // Filtro de pestaña
      if (activeTab === 'installed' && !plugin.is_installed) return false;

      // Filtro de categoría
      if (selectedCategory !== 'all' && plugin.category !== selectedCategory) return false;

      // Filtro de estado en "Mis Plugins"
      if (activeTab === 'installed') {
        if (statusFilter === 'enabled' && !plugin.is_enabled) return false;
        if (statusFilter === 'disabled' && plugin.is_enabled) return false;
      }

      // Filtro de búsqueda
      if (searchQuery.trim()) {
        const query = searchQuery.toLowerCase();
        const matchName = plugin.name?.toLowerCase().includes(query);
        const matchDesc = plugin.description?.toLowerCase().includes(query);
        const matchSlug = plugin.slug?.toLowerCase().includes(query);
        const matchAuthor = plugin.author?.toLowerCase().includes(query);
        if (!matchName && !matchDesc && !matchSlug && !matchAuthor) return false;
      }

      return true;
    });
  }, [plugins, activeTab, selectedCategory, statusFilter, searchQuery]);

  return (
    <div className="space-y-6 pb-12 select-none">
      {/* 1. Header con Título, Métricas y Botón Global de Refresco */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 shadow-lg relative overflow-hidden transition-all duration-200">
        <div className="absolute top-0 right-0 w-96 h-96 bg-red-600/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />

        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
          <div className="space-y-1.5">
            <div className="flex items-center gap-3">
              <div className="p-2.5 bg-red-600/10 border border-red-600/20 rounded-xl text-[#E50914] shadow-inner">
                <Puzzle className="w-6 h-6" />
              </div>
              <div>
                <h1 className="text-xl md:text-2xl font-black tracking-wide text-zinc-100 flex items-center gap-2">
                  Complementos y Extensiones
                  <span className="text-[11px] font-mono uppercase bg-zinc-900 text-zinc-400 px-2 py-0.5 rounded border border-zinc-800 tracking-wider">
                    Ecosistema Core
                  </span>
                </h1>
                <p className="text-xs md:text-sm text-zinc-400">
                  Administre proveedores de metadatos, soporte de subtítulos automáticos, sintonizadores de TV y pasarelas de red.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <button
              onClick={() => fetchPlugins(false)}
              disabled={refreshing}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-700/80 transition-all duration-200 font-semibold text-xs shadow-sm hover:border-zinc-600 cursor-pointer disabled:opacity-50"
              title="Recargar catálogo de complementos"
            >
              <RefreshCw className={`w-3.5 h-3.5 text-[#E50914] ${refreshing ? 'animate-spin' : ''}`} />
              <span>{refreshing ? 'Actualizando...' : 'Recargar Catálogo'}</span>
            </button>
          </div>
        </div>

        {/* 2. Tarjetas de Resumen KPI */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-6 mt-6 border-t border-zinc-800/80">
          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Plugins Instalados</p>
              <p className="text-xl font-bold text-zinc-100 mt-0.5">
                {summary.total_installed}
                <span className="text-xs font-normal text-zinc-500 ml-1">/ {summary.total_catalog}</span>
              </p>
            </div>
            <div className="p-2 bg-zinc-800/60 rounded-lg text-zinc-400">
              <Layers className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Activos en Red</p>
              <p className="text-xl font-bold text-emerald-400 mt-0.5 flex items-center gap-2">
                {summary.total_enabled}
                {summary.total_enabled > 0 && <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping" />}
              </p>
            </div>
            <div className="p-2 bg-emerald-500/10 rounded-lg text-emerald-400">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">En Pausa</p>
              <p className="text-xl font-bold text-amber-400 mt-0.5">{summary.total_disabled}</p>
            </div>
            <div className="p-2 bg-amber-500/10 rounded-lg text-amber-400">
              <Activity className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Disponibles</p>
              <p className="text-xl font-bold text-cyan-400 mt-0.5">
                {Math.max(0, (summary.total_catalog || 0) - (summary.total_installed || 0))}
              </p>
            </div>
            <div className="p-2 bg-cyan-500/10 rounded-lg text-cyan-400">
              <Sparkles className="w-4 h-4" />
            </div>
          </div>
        </div>
      </div>

      {/* 3. Navegación por Pestañas (Pill Buttons Oscuros FlixHN) y Barra de Búsqueda */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-4 shadow-md space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          {/* Selector de Pestañas */}
          <div className="inline-flex p-1 bg-[#121212] border border-zinc-800/90 rounded-xl self-start">
            <button
              onClick={() => setActiveTab('installed')}
              className={`flex items-center gap-2.5 px-4 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'installed'
                  ? 'bg-[#E50914] text-white shadow-md'
                  : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60'
              }`}
            >
              <Puzzle className="w-3.5 h-3.5" />
              <span>Mis Plugins</span>
              <span
                className={`text-[10px] font-mono px-1.5 py-0.2 rounded ${
                  activeTab === 'installed' ? 'bg-black/30 text-white' : 'bg-zinc-800 text-zinc-400'
                }`}
              >
                {summary.total_installed}
              </span>
            </button>

            <button
              onClick={() => setActiveTab('catalog')}
              className={`flex items-center gap-2.5 px-4 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'catalog'
                  ? 'bg-[#E50914] text-white shadow-md'
                  : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60'
              }`}
            >
              <Sparkles className="w-3.5 h-3.5" />
              <span>Catálogo</span>
              <span
                className={`text-[10px] font-mono px-1.5 py-0.2 rounded ${
                  activeTab === 'catalog' ? 'bg-black/30 text-white' : 'bg-zinc-800 text-zinc-400'
                }`}
              >
                {summary.total_catalog}
              </span>
            </button>
          </div>

          {/* Buscador en tiempo real */}
          <div className="relative flex-1 max-w-md">
            <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Buscar por nombre, función o autor..."
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl pl-10 pr-9 py-2 text-xs text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-[#E50914] transition-colors"
            />
            {searchQuery && (
              <button
                onClick={() => setSearchQuery('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* Filtros por Categoría */}
        <div className="flex items-center gap-2 overflow-x-auto pt-2 border-t border-zinc-800/60 pb-1 scrollbar-none">
          <span className="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider shrink-0 flex items-center gap-1.5 mr-1">
            <Filter className="w-3 h-3" /> Categoría:
          </span>

          <button
            onClick={() => setSelectedCategory('all')}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors shrink-0 cursor-pointer ${
              selectedCategory === 'all'
                ? 'bg-zinc-700 text-white font-bold'
                : 'bg-zinc-900/80 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 border border-zinc-800/80'
            }`}
          >
            Todas ({plugins.filter((p) => activeTab === 'catalog' || p.is_installed).length})
          </button>

          {Object.entries(CATEGORY_THEMES).map(([catKey, theme]) => {
            const count = plugins.filter(
              (p) => p.category === catKey && (activeTab === 'catalog' || p.is_installed)
            ).length;

            return (
              <button
                key={catKey}
                onClick={() => setSelectedCategory(catKey)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors shrink-0 flex items-center gap-1.5 cursor-pointer ${
                  selectedCategory === catKey
                    ? 'bg-zinc-700 text-white font-bold'
                    : 'bg-zinc-900/80 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 border border-zinc-800/80'
                }`}
              >
                <span>{theme.label}</span>
                <span className="text-[10px] font-mono text-zinc-400">({count})</span>
              </button>
            );
          })}

          {activeTab === 'installed' && (
            <div className="ml-auto flex items-center gap-1.5 pl-3 border-l border-zinc-800/80 shrink-0">
              <span className="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">Estado:</span>
              <button
                onClick={() => setStatusFilter('all')}
                className={`px-2 py-1 rounded text-xs transition-colors cursor-pointer ${
                  statusFilter === 'all' ? 'text-white font-bold bg-zinc-800' : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                Todos
              </button>
              <button
                onClick={() => setStatusFilter('enabled')}
                className={`px-2 py-1 rounded text-xs transition-colors cursor-pointer ${
                  statusFilter === 'enabled' ? 'text-emerald-400 font-bold bg-emerald-500/10' : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                Activos
              </button>
              <button
                onClick={() => setStatusFilter('disabled')}
                className={`px-2 py-1 rounded text-xs transition-colors cursor-pointer ${
                  statusFilter === 'disabled' ? 'text-amber-400 font-bold bg-amber-500/10' : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                Pausados
              </button>
            </div>
          )}
        </div>
      </div>

      {/* 4. Grid Card View (Tarjetas de Plugin con estética FlixHN) */}
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <div
              key={i}
              className="bg-[#1a1a1a] border border-[#262626] rounded-2xl p-5 shadow-sm space-y-4 animate-pulse"
            >
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 bg-zinc-800 rounded-xl" />
                <div className="space-y-2 flex-1">
                  <div className="h-4 w-32 bg-zinc-800 rounded" />
                  <div className="h-3 w-20 bg-zinc-800/60 rounded" />
                </div>
              </div>
              <div className="h-12 bg-zinc-900/60 rounded-lg" />
              <div className="h-8 bg-zinc-900/40 rounded-lg" />
            </div>
          ))}
        </div>
      ) : displayedPlugins.length === 0 ? (
        <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-12 text-center space-y-4">
          <div className="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center mx-auto text-zinc-500">
            <Puzzle className="w-8 h-8" />
          </div>
          <div className="space-y-1">
            <h3 className="text-base font-bold text-zinc-200">No se encontraron complementos</h3>
            <p className="text-xs text-zinc-400 max-w-md mx-auto">
              {searchQuery
                ? `No hay extensiones que coincidan con la búsqueda "${searchQuery}". Intente con otro término o limpie los filtros.`
                : activeTab === 'installed'
                ? 'Aún no tiene complementos instalados en este nodo. Explore la pestaña "Catálogo" para incorporar nuevas capacidades a FlixHN.'
                : 'No hay complementos disponibles en la categoría seleccionada.'}
            </p>
          </div>
          {searchQuery && (
            <button
              onClick={() => {
                setSearchQuery('');
                setSelectedCategory('all');
                setStatusFilter('all');
              }}
              className="px-4 py-2 rounded-xl text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-200 transition-colors cursor-pointer"
            >
              Restablecer Filtros
            </button>
          )}
          {activeTab === 'installed' && !searchQuery && (
            <button
              onClick={() => setActiveTab('catalog')}
              className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer"
            >
              <Sparkles className="w-4 h-4" />
              <span>Explorar Catálogo FlixHN</span>
            </button>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {displayedPlugins.map((plugin) => {
            const IconComponent = ICON_MAP[plugin.icon_name] || Puzzle;
            const theme = CATEGORY_THEMES[plugin.category] || CATEGORY_THEMES.metadata;
            const isActionRunning = actionLoadingSlugs.has(plugin.slug);

            return (
              <div
                key={plugin.slug}
                className={`bg-[#1a1a1a] border border-[#262626] rounded-2xl p-5 shadow-lg flex flex-col justify-between transition-all duration-200 ${theme.borderHover} hover:shadow-2xl hover:-translate-y-0.5 group`}
              >
                {/* Cabecera de la Tarjeta */}
                <div>
                  <div className="flex items-start justify-between gap-3 mb-3.5">
                    <div className="flex items-center gap-3 min-w-0">
                      <div
                        className={`w-12 h-12 rounded-xl border flex items-center justify-center shrink-0 transition-transform duration-200 group-hover:scale-105 shadow-inner ${theme.iconBg}`}
                      >
                        <IconComponent className="w-6 h-6" />
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <h3
                            className="text-sm font-bold text-zinc-100 truncate group-hover:text-white transition-colors"
                            title={plugin.name}
                          >
                            {plugin.name}
                          </h3>
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                            v{plugin.version}
                          </span>
                          <span className="text-[11px] text-zinc-400 truncate" title={plugin.author}>
                            {plugin.author || 'FlixHN'}
                          </span>
                        </div>
                      </div>
                    </div>

                    {/* Badge de Estado */}
                    <div className="shrink-0">
                      {activeTab === 'installed' ? (
                        plugin.is_enabled ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 shadow-sm">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                            Activo
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide bg-amber-500/10 text-amber-400 border border-amber-500/20">
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-400" />
                            Pausado
                          </span>
                        )
                      ) : plugin.is_installed ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                          <Check className="w-3 h-3" />
                          Instalado
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide bg-zinc-800/80 text-zinc-400 border border-zinc-700/60">
                          Disponible
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Categoría Badge */}
                  <div className="mb-3">
                    <span className={`inline-block px-2 py-0.5 rounded-md text-[10px] font-medium ${theme.badge}`}>
                      {theme.label}
                    </span>
                  </div>

                  {/* Cuerpo: Descripción del Plugin */}
                  <p className="text-xs text-zinc-300/90 line-clamp-3 leading-relaxed mb-4">
                    {plugin.description}
                  </p>
                </div>

                {/* Pie de Tarjeta: Controles y Acciones */}
                <div className="pt-3.5 border-t border-zinc-800/80 flex items-center justify-between gap-3">
                  {activeTab === 'installed' ? (
                    <>
                      {/* Switch Activar / Pausar */}
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => handleToggle(plugin)}
                          disabled={isActionRunning}
                          title={plugin.is_enabled ? 'Desactivar complemento' : 'Activar complemento'}
                          className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-50 ${
                            plugin.is_enabled ? 'bg-emerald-500' : 'bg-zinc-700'
                          }`}
                        >
                          <span
                            className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                              plugin.is_enabled ? 'translate-x-4' : 'translate-x-0'
                            }`}
                          />
                        </button>
                        <span className="text-[11px] font-medium text-zinc-400">
                          {plugin.is_enabled ? 'Habilitado' : 'Deshabilitado'}
                        </span>
                      </div>

                      {/* Botones de Configuración y Desinstalación */}
                      <div className="flex items-center gap-1.5">
                        <button
                          onClick={() => handleOpenSettings(plugin)}
                          className="p-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-700/70 hover:border-zinc-500 transition-colors cursor-pointer"
                          title="Ajustes de configuración"
                        >
                          <Settings className="w-4 h-4 text-zinc-300 hover:text-white" />
                        </button>

                        <button
                          onClick={() => setUninstallModal({ open: true, plugin, processing: false })}
                          className="p-2 rounded-xl bg-zinc-900 hover:bg-red-950/30 text-zinc-400 hover:text-red-400 border border-zinc-700/70 hover:border-red-900/50 transition-colors cursor-pointer"
                          title="Desinstalar complemento"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </>
                  ) : (
                    /* En Pestaña Catálogo */
                    <div className="w-full flex items-center justify-between gap-3">
                      <span className="text-[11px] text-zinc-400 font-mono">
                        {plugin.is_installed ? 'Listo para usar' : 'Listo para instalar'}
                      </span>

                      {plugin.is_installed ? (
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => handleOpenSettings(plugin)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold transition-colors cursor-pointer"
                          >
                            <Settings className="w-3.5 h-3.5" />
                            <span>Ajustes</span>
                          </button>
                        </div>
                      ) : (
                        <button
                          onClick={() => handleInstall(plugin)}
                          disabled={isActionRunning}
                          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer disabled:opacity-50"
                        >
                          {isActionRunning ? (
                            <>
                              <Loader2 className="w-3.5 h-3.5 animate-spin" />
                              <span>Instalando...</span>
                            </>
                          ) : (
                            <>
                              <Download className="w-3.5 h-3.5" />
                              <span>Instalar Plugin</span>
                            </>
                          )}
                        </button>
                      )}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* =========================================================================
          5. MODAL DE CONFIGURACIÓN PERSONALIZADA SEGÚN EL PLUGIN (Backdrop desenfocado)
          ========================================================================= */}
      {settingsModal.open && settingsModal.plugin && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          {/* Backdrop con desenfoque profundo */}
          <div
            onClick={() =>
              !settingsModal.saving &&
              setSettingsModal({ open: false, plugin: null, formValues: {}, saving: false, showPassword: {} })
            }
            className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity"
          />

          <div className="relative w-full max-w-2xl bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden z-10 flex flex-col max-h-[90vh]">
            {/* Cabecera del Modal */}
            <div className="p-5 border-b border-zinc-800 flex items-center justify-between bg-[#141414]">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-red-600/10 border border-red-600/20 rounded-xl text-[#E50914]">
                  {React.createElement(ICON_MAP[settingsModal.plugin.icon_name] || Puzzle, {
                    className: 'w-5 h-5',
                  })}
                </div>
                <div>
                  <h3 className="text-base font-bold text-zinc-100 flex items-center gap-2">
                    Configuración de {settingsModal.plugin.name}
                    <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-zinc-800 text-zinc-400">
                      v{settingsModal.plugin.version}
                    </span>
                  </h3>
                  <p className="text-xs text-zinc-400">
                    Ajuste los parámetros operativos, claves API y preferencias de integración.
                  </p>
                </div>
              </div>

              <button
                onClick={() =>
                  !settingsModal.saving &&
                  setSettingsModal({ open: false, plugin: null, formValues: {}, saving: false, showPassword: {} })
                }
                disabled={settingsModal.saving}
                className="p-1.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Contenido Contextual del Formulario con scroll vertical */}
            <div className="p-6 overflow-y-auto space-y-5 text-xs text-zinc-300">
              {/* RENDERIZADO CONTEXTUAL ESPECÍFICO SEGÚN EL SLUG */}
              {renderPluginSpecificSettings(
                settingsModal.plugin,
                settingsModal.formValues,
                (key, value) => {
                  setSettingsModal((prev) => ({
                    ...prev,
                    formValues: { ...prev.formValues, [key]: value },
                  }));
                },
                settingsModal.showPassword,
                (key) => {
                  setSettingsModal((prev) => ({
                    ...prev,
                    showPassword: {
                      ...prev.showPassword,
                      [key]: !prev.showPassword[key],
                    },
                  }));
                }
              )}

              {/* Parámetros Adicionales / Resumen de Estado */}
              <div className="p-3.5 bg-[#121212] border border-zinc-800/80 rounded-xl space-y-2 mt-4">
                <div className="flex items-center gap-2 text-zinc-400">
                  <ShieldCheck className="w-4 h-4 text-emerald-400" />
                  <span className="font-semibold text-zinc-300">Persistencia Segura en Servidor</span>
                </div>
                <p className="text-[11px] text-zinc-400 leading-relaxed">
                  Las claves de API y credenciales se guardan cifradas en la base de datos de FlixHN y se aplican inmediatamente en los procesos en segundo plano.
                </p>
              </div>
            </div>

            {/* Pie del Modal */}
            <div className="p-4 border-t border-zinc-800 bg-[#141414] flex items-center justify-between">
              <span className="text-[11px] text-zinc-400 font-mono">
                Slug: <code className="text-zinc-300">{settingsModal.plugin.slug}</code>
              </span>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() =>
                    setSettingsModal({ open: false, plugin: null, formValues: {}, saving: false, showPassword: {} })
                  }
                  disabled={settingsModal.saving}
                  className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer disabled:opacity-50"
                >
                  Cancelar
                </button>

                <button
                  type="button"
                  onClick={handleSaveSettings}
                  disabled={settingsModal.saving}
                  className="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer disabled:opacity-50"
                >
                  {settingsModal.saving ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Guardando...</span>
                    </>
                  ) : (
                    <>
                      <Save className="w-3.5 h-3.5" />
                      <span>Guardar Configuración</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* =========================================================================
          6. MODAL DE CONFIRMACIÓN DE DESINSTALACIÓN (Cero alertas JS nativas)
          ========================================================================= */}
      {uninstallModal.open && uninstallModal.plugin && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            onClick={() => !uninstallModal.processing && setUninstallModal({ open: false, plugin: null, processing: false })}
            className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity"
          />

          <div className="relative w-full max-w-md bg-[#181818] border border-red-900/40 rounded-2xl shadow-2xl p-6 z-10 space-y-5 animate-scale-up">
            <div className="flex items-start gap-4">
              <div className="p-3 bg-red-600/10 border border-red-600/30 rounded-2xl text-[#E50914] shrink-0">
                <AlertTriangle className="w-7 h-7" />
              </div>
              <div className="space-y-1">
                <h3 className="text-base font-bold text-zinc-100">
                  ¿Desinstalar complemento?
                </h3>
                <p className="text-xs text-zinc-300 leading-relaxed">
                  Está a punto de remover <strong className="text-white">{uninstallModal.plugin.name}</strong> del sistema FlixHN.
                </p>
              </div>
            </div>

            <div className="p-3 bg-red-950/20 border border-red-900/30 rounded-xl text-[11px] text-red-300/90 leading-relaxed">
              <strong>Advertencia de Configuración:</strong> Se eliminarán las claves de API, parámetros personalizados y caché local almacenada para este módulo. Podrá reinstalarlo en cualquier momento desde el catálogo.
            </div>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => setUninstallModal({ open: false, plugin: null, processing: false })}
                disabled={uninstallModal.processing}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer disabled:opacity-50"
              >
                Cancelar
              </button>

              <button
                type="button"
                onClick={handleConfirmUninstall}
                disabled={uninstallModal.processing}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-lg shadow-red-950/50 transition-all cursor-pointer disabled:opacity-50"
              >
                {uninstallModal.processing ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Desinstalando...</span>
                  </>
                ) : (
                  <>
                    <Trash2 className="w-3.5 h-3.5" />
                    <span>Sí, desinstalar complemento</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* =========================================================================
          7. SISTEMA DE NOTIFICACIONES TOAST FLOTANTES
          ========================================================================= */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 flex items-start gap-3 p-4 rounded-xl shadow-2xl max-w-md w-full border backdrop-blur-md animate-slide-up transition-all bg-[#181818]/95 border-zinc-700 text-zinc-100">
          <div className="shrink-0 mt-0.5">
            {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400" />}
            {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400" />}
            {toast.type === 'info' && <Info className="w-5 h-5 text-cyan-400" />}
          </div>

          <div className="flex-1 min-w-0">
            {toast.title && (
              <h4 className="text-xs font-bold text-zinc-100 uppercase tracking-wide">
                {toast.title}
              </h4>
            )}
            <p className="text-xs text-zinc-300 mt-0.5 leading-relaxed">
              {toast.message}
            </p>
          </div>

          <button
            onClick={() => setToast(null)}
            className="text-zinc-400 hover:text-zinc-200 p-0.5 rounded transition-colors"
            title="Cerrar notificación"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Función auxiliar para renderizar los campos contextuales de configuración
 * según el tipo de plugin (TMDb, OpenSubtitles, Webhooks, XmlTV, etc.).
 */
function renderPluginSpecificSettings(plugin, values, onChange, showPassword, togglePassword) {
  const slug = plugin.slug;

  // 1. Metadatos API (TheMovieDB, TheTVDB, OMDb, Fanart, TheAudioDb)
  if (['themoviedb', 'thetvdb', 'omdb', 'fanart-tv', 'theaudiodb'].includes(slug)) {
    return (
      <div className="space-y-4">
        {/* Campo API Key */}
        <div>
          <label className="block text-xs font-semibold text-zinc-300 mb-1">
            Clave de API ({plugin.name})
          </label>
          <div className="relative">
            <input
              type={showPassword.api_key ? 'text' : 'password'}
              value={values.api_key || ''}
              onChange={(e) => onChange('api_key', e.target.value)}
              placeholder="Ingrese su clave de API personal..."
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 placeholder-zinc-600 focus:outline-none focus:border-[#E50914] pr-10 font-mono"
            />
            <button
              type="button"
              onClick={() => togglePassword('api_key')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
            >
              {showPassword.api_key ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>
          <p className="text-[10px] text-zinc-400 mt-1">
            Necesaria para realizar consultas en los servidores remotos de metadatos.
          </p>
        </div>

        {/* Idioma de Metadatos si aplica */}
        {values.language !== undefined && (
          <div>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">
              Idioma Preferido de Metadatos y Sinopsis
            </label>
            <select
              value={values.language || 'es-ES'}
              onChange={(e) => onChange('language', e.target.value)}
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
            >
              <option value="es-ES">Español (Latinoamérica / España) [es-ES]</option>
              <option value="es-MX">Español (México) [es-MX]</option>
              <option value="en-US">English (United States) [en-US]</option>
              <option value="pt-BR">Português (Brasil) [pt-BR]</option>
            </select>
          </div>
        )}

        {/* TMDb: switches específicos */}
        {slug === 'themoviedb' && (
          <div className="space-y-3 pt-2">
            <label className="flex items-center justify-between p-3 bg-[#121212] border border-zinc-800 rounded-xl cursor-pointer">
              <div>
                <span className="text-xs font-medium text-zinc-200">Descargar Carátulas en Alta Definición (HD/4K)</span>
                <p className="text-[10px] text-zinc-400">Prioriza imágenes originales en resolución nativa para televisores 4K.</p>
              </div>
              <input
                type="checkbox"
                checked={values.download_artwork_hd ?? true}
                onChange={(e) => onChange('download_artwork_hd', e.target.checked)}
                className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 focus:ring-0 focus:ring-offset-0 cursor-pointer"
              />
            </label>

            <label className="flex items-center justify-between p-3 bg-[#121212] border border-zinc-800 rounded-xl cursor-pointer">
              <div>
                <span className="text-xs font-medium text-zinc-200">Incluir Contenido para Adultos</span>
                <p className="text-[10px] text-zinc-400">Permite indexar carátulas y títulos clasificados R/NC-17.</p>
              </div>
              <input
                type="checkbox"
                checked={values.include_adult ?? false}
                onChange={(e) => onChange('include_adult', e.target.checked)}
                className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 focus:ring-0 focus:ring-offset-0 cursor-pointer"
              />
            </label>

            <div>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">
                Persistencia de Caché Local (Días)
              </label>
              <input
                type="number"
                min="1"
                max="365"
                value={values.cache_ttl_days || 30}
                onChange={(e) => onChange('cache_ttl_days', parseInt(e.target.value) || 30)}
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
              />
            </div>
          </div>
        )}

        {/* TheTVDB: PIN y temporada */}
        {slug === 'thetvdb' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
            <div>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">Código PIN TheTVDB</label>
              <input
                type="text"
                value={values.pin || ''}
                onChange={(e) => onChange('pin', e.target.value)}
                placeholder="Código PIN de cuenta..."
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">Orden de Temporadas</label>
              <select
                value={values.preferred_season_type || 'official'}
                onChange={(e) => onChange('preferred_season_type', e.target.value)}
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
              >
                <option value="official">Emisión Oficial (TV)</option>
                <option value="dvd">Orden de Discos DVD</option>
                <option value="absolute">Numeración Absoluta</option>
              </select>
            </div>
          </div>
        )}

        {/* OMDb: Tomatoes toggle */}
        {slug === 'omdb' && (
          <div className="space-y-3 pt-2">
            <label className="flex items-center justify-between p-3 bg-[#121212] border border-zinc-800 rounded-xl cursor-pointer">
              <div>
                <span className="text-xs font-medium text-zinc-200">Incluir Puntaje Rotten Tomatoes</span>
                <p className="text-[10px] text-zinc-400">Descarga porcentajes de Tomatometer y críticas de audiencia.</p>
              </div>
              <input
                type="checkbox"
                checked={values.include_tomatoes ?? true}
                onChange={(e) => onChange('include_tomatoes', e.target.checked)}
                className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 cursor-pointer"
              />
            </label>
          </div>
        )}
      </div>
    );
  }

  // 2. OpenSubtitles (`opensubtitles`)
  if (slug === 'opensubtitles') {
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">Usuario OpenSubtitles</label>
            <input
              type="text"
              value={values.username || ''}
              onChange={(e) => onChange('username', e.target.value)}
              placeholder="Usuario de cuenta..."
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">Contraseña</label>
            <div className="relative">
              <input
                type={showPassword.password ? 'text' : 'password'}
                value={values.password || ''}
                onChange={(e) => onChange('password', e.target.value)}
                placeholder="Contraseña..."
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 pr-10 focus:outline-none focus:border-[#E50914]"
              />
              <button
                type="button"
                onClick={() => togglePassword('password')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
              >
                {showPassword.password ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-zinc-300 mb-1">API Key OpenSubtitles.com (v1 REST)</label>
          <div className="relative">
            <input
              type={showPassword.api_key ? 'text' : 'password'}
              value={values.api_key || ''}
              onChange={(e) => onChange('api_key', e.target.value)}
              placeholder="Consumer API Key..."
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 font-mono pr-10 focus:outline-none focus:border-[#E50914]"
            />
            <button
              type="button"
              onClick={() => togglePassword('api_key')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
            >
              {showPassword.api_key ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-zinc-300 mb-1">
            Idiomas de Descarga Automática (Separados por coma)
          </label>
          <input
            type="text"
            value={Array.isArray(values.download_languages) ? values.download_languages.join(', ') : (values.download_languages || 'es, en')}
            onChange={(e) =>
              onChange(
                'download_languages',
                e.target.value.split(',').map((s) => s.trim()).filter(Boolean)
              )
            }
            placeholder="es, en, pt..."
            className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
          />
          <p className="text-[10px] text-zinc-400 mt-1">Códigos ISO 639-1: 'es' para Español, 'en' para Inglés.</p>
        </div>

        <label className="flex items-center justify-between p-3 bg-[#121212] border border-zinc-800 rounded-xl cursor-pointer">
          <div>
            <span className="text-xs font-medium text-zinc-200">Descarga Automática en Escaneo</span>
            <p className="text-[10px] text-zinc-400">Busca y descarga subtítulos sincronizados cuando ingresa nuevo contenido.</p>
          </div>
          <input
            type="checkbox"
            checked={values.auto_download ?? true}
            onChange={(e) => onChange('auto_download', e.target.checked)}
            className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 cursor-pointer"
          />
        </label>
      </div>
    );
  }

  // 3. Webhooks (`webhooks`)
  if (slug === 'webhooks') {
    const availableEvents = [
      { id: 'playback_start', label: 'Inicio de Reproducción', desc: 'Abonado inicia stream en Smart TV o navegador' },
      { id: 'playback_stop', label: 'Fin de Reproducción', desc: 'Sesión terminada o desconectada' },
      { id: 'new_media_scanned', label: 'Nuevo Título Escaneado', desc: 'Película o serie agregada al catálogo' },
      { id: 'device_connected', label: 'Dispositivo Vinculado', desc: 'Nuevo cliente autenticado en red ISP' },
      { id: 'server_alert', label: 'Alertas del Servidor', desc: 'Picos de consumo o advertencias del sistema' },
    ];

    const currentEvents = Array.isArray(values.events) ? values.events : [];

    const handleToggleEvent = (eventId) => {
      if (currentEvents.includes(eventId)) {
        onChange('events', currentEvents.filter((ev) => ev !== eventId));
      } else {
        onChange('events', [...currentEvents, eventId]);
      }
    };

    return (
      <div className="space-y-4">
        <div>
          <label className="block text-xs font-semibold text-zinc-300 mb-1">
            URL del Webhook (Discord / Slack / Telegram / N8N)
          </label>
          <input
            type="url"
            value={values.webhook_url || ''}
            onChange={(e) => onChange('webhook_url', e.target.value)}
            placeholder="https://discord.com/api/webhooks/..."
            className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">Formato del Payload</label>
            <select
              value={values.format || 'discord_embed'}
              onChange={(e) => onChange('format', e.target.value)}
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
            >
              <option value="discord_embed">Discord Embed (con miniatura y carátula)</option>
              <option value="json">JSON Genérico Estándar (N8N / Zapier)</option>
              <option value="slack">Slack Incoming Webhook</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">Token Secreto de Firma (Opcional)</label>
            <input
              type="text"
              value={values.secret_token || ''}
              onChange={(e) => onChange('secret_token', e.target.value)}
              placeholder="HMAC Secret Key..."
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
            />
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-zinc-300 mb-2">
            Eventos a Notificar en Tiempo Real
          </label>
          <div className="space-y-2">
            {availableEvents.map((ev) => {
              const isChecked = currentEvents.includes(ev.id);
              return (
                <label
                  key={ev.id}
                  className={`flex items-center justify-between p-3 rounded-xl border cursor-pointer transition-colors ${
                    isChecked
                      ? 'bg-red-950/15 border-red-900/40 text-zinc-100'
                      : 'bg-[#121212] border-zinc-800 text-zinc-400'
                  }`}
                >
                  <div>
                    <span className="text-xs font-semibold text-zinc-200">{ev.label}</span>
                    <p className="text-[10px] text-zinc-400">{ev.desc}</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={isChecked}
                    onChange={() => handleToggleEvent(ev.id)}
                    className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 cursor-pointer"
                  />
                </label>
              );
            })}
          </div>
        </div>
      </div>
    );
  }

  // 4. Televisión y Guía EPG (`xmltv`, `m3u-tv-tuner`, `iptv-guide-data`)
  if (['xmltv', 'm3u-tv-tuner', 'iptv-guide-data'].includes(slug)) {
    return (
      <div className="space-y-4">
        {slug === 'xmltv' && (
          <>
            <div>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">URL de Fuente EPG XMLTV</label>
              <input
                type="url"
                value={values.xmltv_url || ''}
                onChange={(e) => onChange('xmltv_url', e.target.value)}
                placeholder="http://servidor-onnet/epg.xml"
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
              />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-zinc-300 mb-1">Zona Horaria (Desfase)</label>
                <select
                  value={values.timezone_offset || '-06:00'}
                  onChange={(e) => onChange('timezone_offset', e.target.value)}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
                >
                  <option value="-06:00">CST (Honduras / Centroamérica -06:00)</option>
                  <option value="-05:00">EST (Bogotá / Lima / Miami -05:00)</option>
                  <option value="00:00">UTC (Universal Coordinated Time)</option>
                  <option value="+01:00">CET (Madrid / París +01:00)</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-zinc-300 mb-1">Días de Retención en BD</label>
                <input
                  type="number"
                  min="1"
                  max="30"
                  value={values.retention_days || 7}
                  onChange={(e) => onChange('retention_days', parseInt(e.target.value) || 7)}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
                />
              </div>
            </div>
          </>
        )}

        {slug === 'm3u-tv-tuner' && (
          <div className="space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-zinc-300 mb-1">Búfer de Stream (ms)</label>
                <input
                  type="number"
                  min="500"
                  max="10000"
                  step="500"
                  value={values.stream_buffer_ms || 2000}
                  onChange={(e) => onChange('stream_buffer_ms', parseInt(e.target.value) || 2000)}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-zinc-300 mb-1">Máx. Streams Simultáneos</label>
                <input
                  type="number"
                  min="1"
                  max="1000"
                  value={values.max_simultaneous_streams || 100}
                  onChange={(e) => onChange('max_simultaneous_streams', parseInt(e.target.value) || 100)}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
                />
              </div>
            </div>
            <div>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">User-Agent HTTP Personalizado</label>
              <input
                type="text"
                value={values.user_agent || 'FlixHN-IPTV/2.1'}
                onChange={(e) => onChange('user_agent', e.target.value)}
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
              />
            </div>
          </div>
        )}
      </div>
    );
  }

  // 5. Otros Plugins (Cinema Intros, DLNA, Port Mapper, Backup, Estadísticas, NFO, etc.)
  // Renderizado dinámico y estructurado para cualquier clave JSON
  return (
    <div className="space-y-3">
      {Object.entries(values).map(([key, val]) => {
        const fieldLabel = key
          .replace(/_/g, ' ')
          .replace(/\b\w/g, (l) => l.toUpperCase());

        if (typeof val === 'boolean') {
          return (
            <label
              key={key}
              className="flex items-center justify-between p-3 bg-[#121212] border border-zinc-800 rounded-xl cursor-pointer"
            >
              <div>
                <span className="text-xs font-medium text-zinc-200">{fieldLabel}</span>
                <p className="text-[10px] text-zinc-400">Parámetro booleano de ejecución.</p>
              </div>
              <input
                type="checkbox"
                checked={val}
                onChange={(e) => onChange(key, e.target.checked)}
                className="w-4 h-4 rounded text-red-600 bg-zinc-900 border-zinc-700 cursor-pointer"
              />
            </label>
          );
        }

        if (typeof val === 'number') {
          return (
            <div key={key}>
              <label className="block text-xs font-semibold text-zinc-300 mb-1">{fieldLabel}</label>
              <input
                type="number"
                value={val}
                onChange={(e) => onChange(key, parseFloat(e.target.value) || 0)}
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-zinc-100 focus:outline-none focus:border-[#E50914]"
              />
            </div>
          );
        }

        return (
          <div key={key}>
            <label className="block text-xs font-semibold text-zinc-300 mb-1">{fieldLabel}</label>
            <input
              type="text"
              value={typeof val === 'object' ? JSON.stringify(val) : val}
              onChange={(e) => onChange(key, e.target.value)}
              className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-zinc-100 font-mono focus:outline-none focus:border-[#E50914]"
            />
          </div>
        );
      })}
    </div>
  );
}
