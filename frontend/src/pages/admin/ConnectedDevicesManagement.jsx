import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import api from '../../services/api';
import {
  Tv,
  Smartphone,
  Tablet,
  Monitor,
  Laptop,
  Globe,
  Play,
  Pause,
  Square,
  Ban,
  ShieldAlert,
  ShieldCheck,
  Edit3,
  Search,
  Filter,
  LayoutGrid,
  List,
  RefreshCw,
  Clock,
  CheckCircle2,
  AlertCircle,
  X,
  Activity,
  Sliders,
  Sparkles,
  Maximize2,
  Eye,
  LogOut,
  Radio,
  Film,
  Check,
  Copy,
  Wifi,
  HardDrive,
  Info,
  Server,
  Zap,
} from 'lucide-react';

/**
 * Función auxiliar para formatear segundos a HH:MM:SS
 */
const formatSeconds = (sec) => {
  if (!sec || isNaN(sec) || sec <= 0) return '00:00:00';
  const hours = Math.floor(sec / 3600);
  const minutes = Math.floor((sec % 3600) / 60);
  const seconds = Math.floor(sec % 60);
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
};

/**
 * Mapeo dinámico de icono según tipo y sistema operativo
 */
const getDeviceIcon = (device) => {
  const type = device?.device_type?.toLowerCase() || '';
  const os = device?.os_name?.toLowerCase() || '';
  const ua = device?.user_agent?.toLowerCase() || '';

  if (type === 'smart_tv' || os.includes('tv') || os.includes('tizen') || os.includes('webos') || os.includes('roku')) {
    return Tv;
  }
  if (os.includes('ipad') || ua.includes('ipad')) {
    return Tablet;
  }
  if (type === 'ios' || type === 'android' || os.includes('iphone') || os.includes('android')) {
    return Smartphone;
  }
  if (os.includes('macbook') || ua.includes('macintosh') || os.includes('laptop')) {
    return Laptop;
  }
  if (type === 'desktop' || os.includes('windows') || os.includes('linux')) {
    return Monitor;
  }
  return Globe;
};

export default function ConnectedDevicesManagement({ onDevicesChange }) {
  const [devices, setDevices] = useState([]);
  const [summary, setSummary] = useState({
    total: 0,
    online: 0,
    playing: 0,
    paused: 0,
    blocked: 0,
    idle: 0,
  });

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [autoPolling, setAutoPolling] = useState(true);
  const [lastUpdated, setLastUpdated] = useState(new Date());

  // Vistas duales (Grid / List) con persistencia en localStorage
  const [viewMode, setViewMode] = useState(() => {
    try {
      return localStorage.getItem('flixhn_devices_view_mode') || 'grid';
    } catch (e) {
      return 'grid';
    }
  });

  const handleSetViewMode = (mode) => {
    setViewMode(mode);
    try {
      localStorage.setItem('flixhn_devices_view_mode', mode);
    } catch (e) {}
  };

  // Filtros reactivos
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all'); // all, playing, paused, blocked

  // Modal de Detalle
  const [selectedDevice, setSelectedDevice] = useState(null);

  // Modal de Confirmación Estilizado (Cero alertas nativas)
  const [confirmModal, setConfirmModal] = useState({
    open: false,
    title: '',
    message: '',
    confirmText: 'Confirmar',
    cancelText: 'Cancelar',
    isDanger: true,
    action: null,
  });

  // Modal / Edición de Alias en vivo
  const [aliasModal, setAliasModal] = useState({
    open: false,
    device: null,
    newName: '',
  });

  // Notificaciones Toast (Cero alertas nativas)
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', title?: string, message: string }

  const showToast = useCallback((type, message, title = '') => {
    setToast({ type, message, title });
  }, []);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => {
      setToast(null);
    }, 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Cronómetro local que avanza cada segundo para actualizar la duración de las sesiones en vivo
  const [tick, setTick] = useState(0);
  useEffect(() => {
    const timer = setInterval(() => {
      setTick((t) => t + 1);
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  // Carga principal de datos desde la API
  const fetchDevices = useCallback(
    async (isSilent = false) => {
      if (!isSilent) setRefreshing(true);

      try {
        const res = await api.get('/admin/devices', {
          params: {
            search: searchQuery,
            status: statusFilter,
          },
        });

        if (res.data?.status === 'success') {
          const list = res.data.devices || [];
          const summ = res.data.summary || {
            total: list.length,
            online: list.filter((d) => d.is_online).length,
            playing: list.filter((d) => d.playback_state === 'playing').length,
            paused: list.filter((d) => d.playback_state === 'paused').length,
            blocked: list.filter((d) => d.is_blocked).length,
            idle: list.filter((d) => d.playback_state === 'stopped').length,
          };

          setDevices(list);
          setSummary(summ);
          setLastUpdated(new Date());

          if (typeof onDevicesChange === 'function') {
            onDevicesChange(summ.online);
          }

          // Si hay un modal abierto con un dispositivo seleccionado, actualizar sus datos
          setSelectedDevice((prevSelected) => {
            if (!prevSelected) return null;
            const updated = list.find((d) => d.id === prevSelected.id);
            return updated || prevSelected;
          });
        }
      } catch (err) {
        console.error('Error cargando dispositivos:', err);
        if (!isSilent) {
          showToast('error', 'No fue posible obtener los dispositivos en tiempo real.', 'Error de Red');
        }
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [searchQuery, statusFilter, onDevicesChange, showToast]
  );

  // Carga inicial y reacción a filtros
  useEffect(() => {
    fetchDevices();
  }, [fetchDevices]);

  // Polling automático cada 5 segundos
  useEffect(() => {
    if (!autoPolling) return;
    const interval = setInterval(() => {
      fetchDevices(true);
    }, 5000);
    return () => clearInterval(interval);
  }, [autoPolling, fetchDevices]);

  // 1. Control Remoto de Reproducción (Pausar / Reanudar / Detener)
  const handlePlaybackControl = async (device, action) => {
    if (!device) return;

    try {
      const res = await api.post(`/admin/devices/${device.id}/playback-control`, { action });
      if (res.data?.status === 'success') {
        const actionLabel = action === 'pause' ? 'Pausado' : action === 'resume' ? 'Reanudado' : 'Detenido';
        showToast('success', res.data.message || `Dispositivo ${actionLabel} exitosamente.`, 'Control Remoto');
        fetchDevices(true);
      }
    } catch (err) {
      showToast('error', err.response?.data?.message || 'Error al enviar comando remoto.', 'Fallo de Control');
    }
  };

  // 2. Alternar Bloqueo de Acceso
  const handleToggleBlock = (device) => {
    if (!device) return;

    const willBlock = !device.is_blocked;

    setConfirmModal({
      open: true,
      title: willBlock ? '¿Bloquear Acceso a este Dispositivo?' : '¿Desbloquear Dispositivo?',
      message: willBlock
        ? `Se suspenderá de inmediato el flujo HLS y se denegará cualquier petición de streaming proveniente de la IP (${device.ip_address || 'Asignada'}) o identificador del equipo "${device.custom_name}".`
        : `El dispositivo "${device.custom_name}" recuperará el acceso al catálogo y a los sintonizadores en directo de FlixHN.`,
      confirmText: willBlock ? 'Bloquear Dispositivo' : 'Desbloquear Acceso',
      cancelText: 'Cancelar',
      isDanger: willBlock,
      action: async () => {
        try {
          const res = await api.post(`/admin/devices/${device.id}/toggle-block`, {
            is_blocked: willBlock,
          });
          if (res.data?.status === 'success') {
            showToast(
              willBlock ? 'info' : 'success',
              res.data.message || (willBlock ? 'Dispositivo bloqueado.' : 'Dispositivo desbloqueado.'),
              willBlock ? 'Acceso Denegado' : 'Acceso Restablecido'
            );
            fetchDevices(true);
          }
        } catch (err) {
          showToast('error', err.response?.data?.message || 'Error al cambiar bloqueo.', 'Error');
        }
      },
    });
  };

  // 3. Terminar / Expulsar Sesión
  const handleTerminateSession = (device) => {
    if (!device) return;

    setConfirmModal({
      open: true,
      title: '¿Terminar y Expulsar Sesión Activa?',
      message: `Se cerrará forzosamente la sesión de streaming en "${device.custom_name}". El reproductor del cliente se detendrá inmediatamente.`,
      confirmText: 'Expulsar Sesión',
      cancelText: 'Cancelar',
      isDanger: true,
      action: async () => {
        try {
          const res = await api.delete(`/admin/devices/${device.id}/session`);
          if (res.data?.status === 'success') {
            showToast('info', res.data.message || 'Sesión finalizada con éxito.', 'Sesión Expulsada');
            fetchDevices(true);
          }
        } catch (err) {
          showToast('error', err.response?.data?.message || 'Error al expulsar la sesión.', 'Error');
        }
      },
    });
  };

  // 4. Guardar Alias / Nombre Personalizado
  const handleSaveAlias = async (e) => {
    if (e) e.preventDefault();
    if (!aliasModal.device || !aliasModal.newName.trim()) return;

    try {
      const res = await api.put(`/admin/devices/${aliasModal.device.id}/name`, {
        custom_name: aliasModal.newName.trim(),
      });
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Alias del dispositivo actualizado.', 'Nombre Guardado');
        setAliasModal({ open: false, device: null, newName: '' });
        fetchDevices(true);
      }
    } catch (err) {
      showToast('error', err.response?.data?.message || 'Error al renombrar el dispositivo.', 'Error');
    }
  };

  // Cálculo en vivo de duración de sesión de un dispositivo
  const getLiveDurationFormatted = (device) => {
    if (!device?.session_started_at) return '00:00:00';
    try {
      const startMs = new Date(device.session_started_at).getTime();
      const nowMs = Date.now();
      const diffSec = Math.max(0, Math.floor((nowMs - startMs) / 1000));
      return formatSeconds(diffSec);
    } catch (e) {
      return device.session_duration_formatted || '00:00:00';
    }
  };

  // Filtrado reactivo en memoria para rapidez absoluta
  const filteredDevices = useMemo(() => {
    return devices.filter((dev) => {
      // Filtro de estado
      if (statusFilter === 'playing' && (dev.playback_state !== 'playing' || dev.is_blocked)) return false;
      if (statusFilter === 'paused' && (dev.playback_state !== 'paused' || dev.is_blocked)) return false;
      if (statusFilter === 'blocked' && !dev.is_blocked) return false;

      // Filtro de búsqueda
      if (!searchQuery.trim()) return true;
      const q = searchQuery.toLowerCase();
      const name = (dev.custom_name || '').toLowerCase();
      const ip = (dev.ip_address || '').toLowerCase();
      const client = (dev.client_identifier || '').toLowerCase();
      const user = (dev.user?.customer_name || dev.user?.username || '').toLowerCase();
      const media = (dev.current_media_title || '').toLowerCase();
      const os = (dev.os_name || '').toLowerCase();
      const browser = (dev.browser_name || '').toLowerCase();

      return (
        name.includes(q) ||
        ip.includes(q) ||
        client.includes(q) ||
        user.includes(q) ||
        media.includes(q) ||
        os.includes(q) ||
        browser.includes(q)
      );
    });
  }, [devices, statusFilter, searchQuery]);

  return (
    <div className="space-y-6 text-zinc-100 font-sans pb-12 select-none">
      {/* 1. Header Principal y Controles de Telemetría */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-5 md:p-6 shadow-xl relative overflow-hidden">
        <div className="absolute top-0 right-0 w-96 h-96 bg-red-600/5 rounded-full blur-3xl pointer-events-none" />

        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 relative z-10">
          <div>
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-[#E50914]/15 border border-[#E50914]/30 flex items-center justify-center text-[#E50914]">
                <Radio className="w-5 h-5 animate-pulse" />
              </div>
              <div>
                <h1 className="text-xl md:text-2xl font-black text-white tracking-tight flex items-center gap-3">
                  Dispositivos Conectados
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                    <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                    {summary.online} en vivo
                  </span>
                </h1>
                <p className="text-xs md:text-sm text-zinc-400 mt-0.5">
                  Monitoreo de telemetría de streaming HLS, control remoto de reproducción y gestión de acceso ISP.
                </p>
              </div>
            </div>
          </div>

          {/* Botones de acción y Polling */}
          <div className="flex flex-wrap items-center gap-2.5">
            <button
              onClick={() => setAutoPolling(!autoPolling)}
              title={autoPolling ? 'Desactivar polling automático' : 'Activar polling automático'}
              className={`inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold border transition-all cursor-pointer ${
                autoPolling
                  ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                  : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-zinc-200'
              }`}
            >
              <span className={`w-2 h-2 rounded-full ${autoPolling ? 'bg-emerald-400 animate-pulse' : 'bg-zinc-600'}`} />
              <span>{autoPolling ? 'En Vivo (5s)' : 'En Pausa'}</span>
            </button>

            <button
              onClick={() => fetchDevices(false)}
              disabled={refreshing}
              title="Refrescar lista ahora"
              className="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 transition-all cursor-pointer disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
              <span className="hidden sm:inline">Refrescar</span>
            </button>
          </div>
        </div>

        {/* Barra de KPIs Resumen */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-6 pt-5 border-t border-zinc-800/80">
          <div className="bg-[#121212] border border-zinc-800/90 rounded-xl p-3">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider block">Total Registrados</span>
            <span className="text-xl font-black text-white mt-1 block">{summary.total}</span>
          </div>
          <div className="bg-[#121212] border border-emerald-500/20 rounded-xl p-3">
            <span className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
              En Línea
            </span>
            <span className="text-xl font-black text-emerald-400 mt-1 block">{summary.online}</span>
          </div>
          <div className="bg-[#121212] border border-red-500/20 rounded-xl p-3">
            <span className="text-[11px] font-bold text-red-400 uppercase tracking-wider flex items-center gap-1.5">
              <Play className="w-2.5 h-2.5 fill-current" />
              Reproduciendo
            </span>
            <span className="text-xl font-black text-red-400 mt-1 block">{summary.playing}</span>
          </div>
          <div className="bg-[#121212] border border-amber-500/20 rounded-xl p-3">
            <span className="text-[11px] font-bold text-amber-400 uppercase tracking-wider flex items-center gap-1.5">
              <Pause className="w-2.5 h-2.5 fill-current" />
              Pausados
            </span>
            <span className="text-xl font-black text-amber-400 mt-1 block">{summary.paused}</span>
          </div>
          <div className="bg-[#121212] border border-zinc-800/90 rounded-xl p-3">
            <span className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-1.5">
              <Clock className="w-2.5 h-2.5" />
              En Espera
            </span>
            <span className="text-xl font-black text-zinc-300 mt-1 block">{summary.idle}</span>
          </div>
          <div className="bg-[#121212] border border-rose-500/30 rounded-xl p-3">
            <span className="text-[11px] font-bold text-rose-400 uppercase tracking-wider flex items-center gap-1.5">
              <Ban className="w-2.5 h-2.5" />
              Bloqueados
            </span>
            <span className="text-xl font-black text-rose-400 mt-1 block">{summary.blocked}</span>
          </div>
        </div>
      </div>

      {/* 2. Barra de Filtros, Búsqueda y Selector de Vistas Duales */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-[#181818] border border-zinc-800 rounded-2xl p-4 shadow-lg">
        {/* Input de Búsqueda reactivo */}
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-zinc-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar por alias, cliente ISP, IP o contenido..."
            className="w-full bg-[#121212] border border-zinc-800 rounded-xl pl-10 pr-10 py-2.5 text-xs md:text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] transition-all"
          />
          {searchQuery && (
            <button
              onClick={() => setSearchQuery('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-200 p-1"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          )}
        </div>

        {/* Píldoras de Filtro Rápido */}
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 scrollbar-none">
          {[
            { id: 'all', label: 'Todos', count: summary.total },
            { id: 'playing', label: 'Reproduciendo', count: summary.playing },
            { id: 'paused', label: 'Pausados', count: summary.paused },
            { id: 'blocked', label: 'Bloqueados', count: summary.blocked },
          ].map((tab) => {
            const isActive = statusFilter === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => setStatusFilter(tab.id)}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all whitespace-nowrap cursor-pointer ${
                  isActive
                    ? 'bg-[#E50914] text-white shadow-md'
                    : 'bg-[#121212] text-zinc-400 hover:text-zinc-200 border border-zinc-800 hover:border-zinc-700'
                }`}
              >
                <span>{tab.label}</span>
                <span
                  className={`text-[10px] px-1.5 py-0.2 rounded-full font-mono ${
                    isActive ? 'bg-black/30 text-white' : 'bg-zinc-800 text-zinc-400'
                  }`}
                >
                  {tab.count}
                </span>
              </button>
            );
          })}
        </div>

        {/* Selector Toggle de Vistas Duales (Grid / List) */}
        <div className="flex items-center gap-1 bg-[#121212] p-1 rounded-xl border border-zinc-800 shrink-0 self-end md:self-auto">
          <button
            onClick={() => handleSetViewMode('grid')}
            title="Vista de Cuadrícula / Tarjetas Grandes"
            className={`p-2 rounded-lg transition-colors cursor-pointer ${
              viewMode === 'grid'
                ? 'bg-zinc-800 text-[#E50914] shadow-sm font-bold'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <LayoutGrid className="w-4 h-4" />
          </button>
          <button
            onClick={() => handleSetViewMode('list')}
            title="Vista de Tabla / Lista Compacta"
            className={`p-2 rounded-lg transition-colors cursor-pointer ${
              viewMode === 'list'
                ? 'bg-zinc-800 text-[#E50914] shadow-sm font-bold'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <List className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* 3. Renderizado de Dispositivos (Vistas Duales) */}
      {loading ? (
        <div className="py-20 flex flex-col items-center justify-center space-y-4">
          <div className="w-10 h-10 border-3 border-[#E50914] border-t-transparent rounded-full animate-spin" />
          <p className="text-xs text-zinc-400 font-mono tracking-wide">
            Consultando sesiones de dispositivos en vivo...
          </p>
        </div>
      ) : filteredDevices.length === 0 ? (
        <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-12 text-center shadow-lg">
          <div className="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-600 mx-auto mb-4">
            <Radio className="w-8 h-8 opacity-40 text-zinc-400" />
          </div>
          <h3 className="text-base font-bold text-zinc-200">
            {searchQuery || statusFilter !== 'all'
              ? 'No se encontraron dispositivos conectados'
              : 'No hay dispositivos reproduciendo en este momento'}
          </h3>
          <p className="text-xs text-zinc-400 max-w-md mx-auto mt-2 leading-relaxed">
            {searchQuery || statusFilter !== 'all'
              ? `No hay ningún equipo que coincida con "${searchQuery || statusFilter}".`
              : 'Los equipos aparecerán aquí automáticamente cuando los usuarios inicien sesión y reproduzcan contenido.'}
          </p>
          {(searchQuery || statusFilter !== 'all') && (
            <button
              onClick={() => {
                setSearchQuery('');
                setStatusFilter('all');
              }}
              className="mt-5 inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 transition-all cursor-pointer"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              <span>Limpiar Filtros</span>
            </button>
          )}
        </div>
      ) : viewMode === 'grid' ? (
        /* ================= VISTA DE CUADRÍCULA / TARJETAS GRANDES ================= */
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          {filteredDevices.map((device) => {
            const DeviceIcon = getDeviceIcon(device);
            const isPlaying = device.playback_state === 'playing';
            const isPaused = device.playback_state === 'paused';
            const isBlocked = device.is_blocked;
            const liveDuration = getLiveDurationFormatted(device);

            return (
              <div
                key={device.id}
                className={`bg-[#181818] rounded-2xl border transition-all duration-300 flex flex-col justify-between overflow-hidden shadow-xl hover:shadow-2xl hover:border-zinc-700 group relative ${
                  isBlocked
                    ? 'border-red-950/60 bg-gradient-to-b from-[#181818] to-red-950/10'
                    : isPlaying
                    ? 'border-zinc-800 hover:border-[#E50914]/50'
                    : 'border-zinc-800'
                }`}
              >
                {/* Cabecera de la Tarjeta */}
                <div className="p-5">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      {/* Avatar / Icono Grande */}
                      <div
                        onClick={() => setSelectedDevice(device)}
                        className={`w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 cursor-pointer transition-transform group-hover:scale-105 ${
                          isBlocked
                            ? 'bg-red-500/10 text-red-400 border border-red-500/30'
                            : isPlaying
                            ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                            : isPaused
                            ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                            : 'bg-zinc-800 text-zinc-400 border border-zinc-700'
                        }`}
                      >
                        <DeviceIcon className="w-6 h-6" />
                      </div>

                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <h3
                            onClick={() => setSelectedDevice(device)}
                            title={device.custom_name}
                            className="text-sm font-bold text-white truncate cursor-pointer hover:text-[#E50914] transition-colors"
                          >
                            {device.custom_name}
                          </h3>
                          <button
                            onClick={() =>
                              setAliasModal({
                                open: true,
                                device,
                                newName: device.custom_name,
                              })
                            }
                            className="text-zinc-500 hover:text-zinc-200 transition-colors p-1"
                            title="Editar alias"
                          >
                            <Edit3 className="w-3 h-3" />
                          </button>
                        </div>
                        <p className="text-[11px] text-zinc-400 truncate mt-0.5">
                          {device.user?.customer_name || 'Abonado ISP'} •{' '}
                          <span className="font-mono text-zinc-500">{device.client_identifier || 'CLI'}</span>
                        </p>
                      </div>
                    </div>

                    {/* Badge de Estado */}
                    <div className="shrink-0">
                      {isBlocked ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                          <Ban className="w-2.5 h-2.5" />
                          Bloqueado
                        </span>
                      ) : isPlaying ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 shadow-sm">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                          En directo
                        </span>
                      ) : isPaused ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                          <Pause className="w-2.5 h-2.5 fill-current" />
                          Pausado
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-zinc-800 text-zinc-400 border border-zinc-700">
                          <Clock className="w-2.5 h-2.5" />
                          En espera
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Sección de Reproducción Actual */}
                  <div
                    onClick={() => setSelectedDevice(device)}
                    className="mt-4 bg-[#121212] border border-zinc-800/80 rounded-xl p-3 cursor-pointer hover:border-zinc-700 transition-colors"
                  >
                    <div className="flex items-center gap-3">
                      {device.current_media_poster ? (
                        <img
                          src={device.current_media_poster}
                          alt={device.current_media_title || 'Póster'}
                          className="w-10 h-14 object-cover rounded-lg shrink-0 border border-zinc-800 bg-zinc-900"
                        />
                      ) : (
                        <div className="w-10 h-14 rounded-lg bg-zinc-800/80 border border-zinc-700 flex items-center justify-center shrink-0 text-zinc-500">
                          {device.current_media_type === 'livetv' ? (
                            <Radio className="w-5 h-5 text-red-500" />
                          ) : (
                            <Film className="w-5 h-5" />
                          )}
                        </div>
                      )}

                      <div className="min-w-0 flex-1">
                        <span className="text-[10px] font-mono uppercase text-zinc-400 tracking-wider flex items-center gap-1.5">
                          {device.current_media_type === 'livetv' ? (
                            <span className="text-red-400 font-bold">● TV en Vivo</span>
                          ) : device.current_media_type === 'movie' ? (
                            'Película VOD'
                          ) : device.current_media_type === 'episode' ? (
                            'Episodio Serie'
                          ) : (
                            'Inactivo'
                          )}
                        </span>
                        <h4 className="text-xs font-bold text-zinc-100 truncate mt-0.5" title={device.current_media_title}>
                          {device.current_media_title || 'Sin reproducción activa'}
                        </h4>

                        {/* Barra de progreso de reproducción */}
                        {device.media_duration_seconds > 0 && (
                          <div className="mt-2 space-y-1">
                            <div className="w-full bg-zinc-800 h-1.5 rounded-full overflow-hidden">
                              <div
                                className="bg-[#E50914] h-full rounded-full transition-all duration-300"
                                style={{ width: `${device.progress_percent || 0}%` }}
                              />
                            </div>
                            <div className="flex items-center justify-between text-[10px] font-mono text-zinc-400">
                              <span>{formatSeconds(device.playback_position_seconds)}</span>
                              <span>{formatSeconds(device.media_duration_seconds)}</span>
                            </div>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Metadatos Rápidos (IP, SO, Tiempo Activo) */}
                  <div className="grid grid-cols-2 gap-2 mt-3 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400">
                    <div>
                      <span className="text-zinc-500 block text-[10px]">Dirección IP</span>
                      <span className="font-mono text-zinc-300 truncate block">{device.ip_address || '127.0.0.1'}</span>
                    </div>
                    <div>
                      <span className="text-zinc-500 block text-[10px]">Tiempo Sesión</span>
                      <span className="font-mono text-emerald-400 font-bold flex items-center gap-1">
                        <Clock className="w-3 h-3" />
                        {liveDuration}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Acciones Rápidas Directas al pie */}
                <div className="bg-[#141414] px-4 py-3 border-t border-zinc-800/80 flex items-center justify-between gap-2">
                  <div className="flex items-center gap-1.5">
                    {/* Botón Pausar / Reanudar */}
                    {!isBlocked && (
                      <button
                        onClick={() => handlePlaybackControl(device, isPlaying ? 'pause' : 'resume')}
                        className={`p-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                          isPlaying
                            ? 'bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/20'
                            : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/20'
                        }`}
                        title={isPlaying ? 'Pausar stream remotamente' : 'Reanudar reproducción'}
                      >
                        {isPlaying ? <Pause className="w-3.5 h-3.5 fill-current" /> : <Play className="w-3.5 h-3.5 fill-current" />}
                      </button>
                    )}

                    {/* Botón Bloquear / Desbloquear */}
                    <button
                      onClick={() => handleToggleBlock(device)}
                      className={`p-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                        isBlocked
                          ? 'bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30'
                          : 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20'
                      }`}
                      title={isBlocked ? 'Desbloquear acceso a este equipo' : 'Bloquear acceso permanentemente'}
                    >
                      {isBlocked ? <ShieldCheck className="w-3.5 h-3.5" /> : <ShieldAlert className="w-3.5 h-3.5" />}
                    </button>
                  </div>

                  <button
                    onClick={() => setSelectedDevice(device)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white transition-all cursor-pointer"
                  >
                    <Eye className="w-3.5 h-3.5" />
                    <span>Detalles</span>
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        /* ================= VISTA DE LISTA / TABLA COMPACTA ================= */
        <div className="bg-[#181818] border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs text-zinc-300">
              <thead className="bg-[#121212] text-zinc-400 uppercase text-[10px] font-bold tracking-wider border-b border-zinc-800">
                <tr>
                  <th className="py-3.5 px-4">Estado</th>
                  <th className="py-3.5 px-4">Dispositivo / SO</th>
                  <th className="py-3.5 px-4">Nombre / Alias</th>
                  <th className="py-3.5 px-4">Cliente ISP</th>
                  <th className="py-3.5 px-4">Dirección IP</th>
                  <th className="py-3.5 px-4">Contenido Activo</th>
                  <th className="py-3.5 px-4">Tiempo Activo</th>
                  <th className="py-3.5 px-4 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/80 font-normal">
                {filteredDevices.map((device) => {
                  const DeviceIcon = getDeviceIcon(device);
                  const isPlaying = device.playback_state === 'playing';
                  const isPaused = device.playback_state === 'paused';
                  const isBlocked = device.is_blocked;
                  const liveDuration = getLiveDurationFormatted(device);

                  return (
                    <tr
                      key={device.id}
                      className="hover:bg-zinc-900/60 transition-colors group cursor-pointer"
                    >
                      {/* Estado */}
                      <td className="py-3 px-4 whitespace-nowrap" onClick={() => setSelectedDevice(device)}>
                        {isBlocked ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                            <Ban className="w-2.5 h-2.5" />
                            Bloqueado
                          </span>
                        ) : isPlaying ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                            En directo
                          </span>
                        ) : isPaused ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                            <Pause className="w-2.5 h-2.5 fill-current" />
                            Pausado
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-zinc-800 text-zinc-400">
                            En espera
                          </span>
                        )}
                      </td>

                      {/* Icono + SO / Navegador */}
                      <td className="py-3 px-4 whitespace-nowrap" onClick={() => setSelectedDevice(device)}>
                        <div className="flex items-center gap-2.5">
                          <div className="w-7 h-7 rounded-lg bg-zinc-800 flex items-center justify-center text-zinc-300 shrink-0">
                            <DeviceIcon className="w-4 h-4" />
                          </div>
                          <div className="min-w-0">
                            <div className="font-semibold text-zinc-200 truncate">{device.os_name || 'Desconocido'}</div>
                            <div className="text-[10px] text-zinc-500 truncate">{device.browser_name || 'Navegador'}</div>
                          </div>
                        </div>
                      </td>

                      {/* Nombre / Alias */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5">
                          <span
                            onClick={() => setSelectedDevice(device)}
                            className="font-bold text-white hover:text-[#E50914] transition-colors truncate max-w-[180px]"
                            title={device.custom_name}
                          >
                            {device.custom_name}
                          </span>
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setAliasModal({ open: true, device, newName: device.custom_name });
                            }}
                            className="text-zinc-500 hover:text-zinc-200 p-0.5"
                            title="Editar alias"
                          >
                            <Edit3 className="w-3 h-3" />
                          </button>
                        </div>
                      </td>

                      {/* Cliente / Contrato ISP */}
                      <td className="py-3 px-4 whitespace-nowrap" onClick={() => setSelectedDevice(device)}>
                        <div className="text-zinc-200">{device.user?.customer_name || 'Abonado'}</div>
                        <div className="text-[10px] font-mono text-zinc-500">{device.client_identifier || 'CLI-ONNET'}</div>
                      </td>

                      {/* Dirección IP */}
                      <td className="py-3 px-4 whitespace-nowrap font-mono text-zinc-300" onClick={() => setSelectedDevice(device)}>
                        {device.ip_address || '127.0.0.1'}
                      </td>

                      {/* Contenido en reproducción */}
                      <td className="py-3 px-4" onClick={() => setSelectedDevice(device)}>
                        <div className="max-w-[200px]">
                          <div className="font-semibold text-zinc-200 truncate" title={device.current_media_title}>
                            {device.current_media_title || 'Inactivo'}
                          </div>
                          {device.media_duration_seconds > 0 && (
                            <div className="w-28 bg-zinc-800 h-1 rounded-full overflow-hidden mt-1">
                              <div
                                className="bg-[#E50914] h-full rounded-full"
                                style={{ width: `${device.progress_percent || 0}%` }}
                              />
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Tiempo de Visualización */}
                      <td className="py-3 px-4 whitespace-nowrap font-mono text-emerald-400 font-semibold" onClick={() => setSelectedDevice(device)}>
                        {liveDuration}
                      </td>

                      {/* Acciones Rápidas */}
                      <td className="py-3 px-4 text-right whitespace-nowrap">
                        <div className="flex items-center justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
                          {!isBlocked && (
                            <button
                              onClick={() => handlePlaybackControl(device, isPlaying ? 'pause' : 'resume')}
                              className={`p-1.5 rounded-lg transition-colors cursor-pointer ${
                                isPlaying
                                  ? 'bg-amber-500/10 hover:bg-amber-500/20 text-amber-400'
                                  : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400'
                              }`}
                              title={isPlaying ? 'Pausar' : 'Reanudar'}
                            >
                              {isPlaying ? <Pause className="w-3.5 h-3.5 fill-current" /> : <Play className="w-3.5 h-3.5 fill-current" />}
                            </button>
                          )}

                          <button
                            onClick={() => handleToggleBlock(device)}
                            className={`p-1.5 rounded-lg transition-colors cursor-pointer ${
                              isBlocked
                                ? 'bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400'
                                : 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-400'
                            }`}
                            title={isBlocked ? 'Desbloquear' : 'Bloquear'}
                          >
                            {isBlocked ? <ShieldCheck className="w-3.5 h-3.5" /> : <ShieldAlert className="w-3.5 h-3.5" />}
                          </button>

                          <button
                            onClick={() => setSelectedDevice(device)}
                            className="p-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white transition-colors cursor-pointer"
                            title="Ver Telemetría Completa"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ================= MODAL 1: DETALLE EN TIEMPO REAL Y CONTROL REMOTO ================= */}
      {selectedDevice && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity"
            onClick={() => setSelectedDevice(null)}
          />

          <div className="relative w-full max-w-3xl bg-[#181818] border border-zinc-800 rounded-3xl shadow-2xl overflow-hidden z-10 flex flex-col max-h-[90vh] animate-scale-in">
            {/* Header del Modal */}
            <div className="p-6 border-b border-zinc-800 bg-[#141414] flex items-center justify-between">
              <div className="flex items-center gap-4">
                {(() => {
                  const ModalIcon = getDeviceIcon(selectedDevice);
                  return (
                    <div className="w-12 h-12 rounded-2xl bg-[#E50914]/15 border border-[#E50914]/30 flex items-center justify-center text-[#E50914]">
                      <ModalIcon className="w-6 h-6" />
                    </div>
                  );
                })()}

                <div>
                  <div className="flex items-center gap-2.5">
                    <h2 className="text-lg font-black text-white">{selectedDevice.custom_name}</h2>
                    <button
                      onClick={() =>
                        setAliasModal({
                          open: true,
                          device: selectedDevice,
                          newName: selectedDevice.custom_name,
                        })
                      }
                      className="text-zinc-400 hover:text-white p-1 rounded transition-colors"
                      title="Editar Alias"
                    >
                      <Edit3 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <p className="text-xs text-zinc-400">
                    {selectedDevice.os_name} • {selectedDevice.browser_name}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-3">
                {selectedDevice.is_blocked ? (
                  <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                    <Ban className="w-3 h-3" />
                    Bloqueado
                  </span>
                ) : selectedDevice.playback_state === 'playing' ? (
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                    <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                    En directo
                  </span>
                ) : selectedDevice.playback_state === 'paused' ? (
                  <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                    <Pause className="w-3 h-3 fill-current" />
                    Pausado
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-zinc-800 text-zinc-400">
                    En espera
                  </span>
                )}

                <button
                  onClick={() => setSelectedDevice(null)}
                  className="p-1.5 rounded-xl text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>

            {/* Cuerpo del Modal con Scroll */}
            <div className="p-6 overflow-y-auto space-y-6">
              {/* Tarjeta de Reproducción Actual */}
              <div className="bg-[#121212] border border-zinc-800 rounded-2xl p-5 relative overflow-hidden">
                <span className="text-[10px] font-mono uppercase text-zinc-400 font-bold tracking-wider block mb-3">
                  Transmisión y Reproducción Activa
                </span>

                <div className="flex flex-col sm:flex-row items-start gap-4">
                  {selectedDevice.current_media_poster ? (
                    <img
                      src={selectedDevice.current_media_poster}
                      alt={selectedDevice.current_media_title || 'Póster'}
                      className="w-20 h-28 object-cover rounded-xl shadow-lg border border-zinc-800 bg-zinc-900 shrink-0"
                    />
                  ) : (
                    <div className="w-20 h-28 rounded-xl bg-zinc-800 border border-zinc-700 flex items-center justify-center shrink-0 text-zinc-500">
                      {selectedDevice.current_media_type === 'livetv' ? (
                        <Radio className="w-8 h-8 text-red-500" />
                      ) : (
                        <Film className="w-8 h-8" />
                      )}
                    </div>
                  )}

                  <div className="flex-1 min-w-0 space-y-2 w-full">
                    <div>
                      <span className="text-xs font-bold text-red-400 uppercase tracking-wide">
                        {selectedDevice.current_media_type === 'livetv'
                          ? 'Sintonizador TV en Directo'
                          : selectedDevice.current_media_type === 'movie'
                          ? 'Película VOD'
                          : 'Episodio / Serie'}
                      </span>
                      <h3 className="text-base font-extrabold text-white mt-0.5">
                        {selectedDevice.current_media_title || 'Sin reproducción activa'}
                      </h3>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
                      <span className="bg-zinc-800/80 px-2.5 py-1 rounded-lg border border-zinc-700/80 font-mono">
                        Bitrate: <strong className="text-zinc-200">{selectedDevice.bitrate || 'Adaptativo HLS'}</strong>
                      </span>
                      <span className="bg-zinc-800/80 px-2.5 py-1 rounded-lg border border-zinc-700/80 font-mono">
                        Resolución: <strong className="text-zinc-200">{selectedDevice.screen_resolution || '1080p FHD'}</strong>
                      </span>
                    </div>

                    {/* Barra de progreso interactiva */}
                    {selectedDevice.media_duration_seconds > 0 && (
                      <div className="space-y-1.5 pt-2">
                        <div className="w-full bg-zinc-800 h-2 rounded-full overflow-hidden">
                          <div
                            className="bg-[#E50914] h-full rounded-full transition-all duration-300"
                            style={{ width: `${selectedDevice.progress_percent || 0}%` }}
                          />
                        </div>
                        <div className="flex items-center justify-between text-xs font-mono text-zinc-400">
                          <span>{formatSeconds(selectedDevice.playback_position_seconds)}</span>
                          <span className="font-bold text-zinc-200">{selectedDevice.progress_percent || 0}%</span>
                          <span>{formatSeconds(selectedDevice.media_duration_seconds)}</span>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Grid de Metadatos del Cliente y Equipo */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="bg-[#121212] border border-zinc-800 rounded-2xl p-4 space-y-3">
                  <h4 className="text-xs font-bold text-zinc-300 uppercase tracking-wider flex items-center gap-2">
                    <Server className="w-4 h-4 text-[#E50914]" />
                    Información de Abonado ISP
                  </h4>

                  <div className="space-y-2 text-xs">
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">Abonado / Contrato:</span>
                      <span className="font-semibold text-zinc-100">{selectedDevice.user?.customer_name || 'Abonado'}</span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">Identificador Cliente:</span>
                      <span className="font-mono text-zinc-200">{selectedDevice.client_identifier || 'CLI-ONNET'}</span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">Dirección IP:</span>
                      <span className="font-mono text-emerald-400">{selectedDevice.ip_address || '127.0.0.1'}</span>
                    </div>
                    <div className="flex justify-between py-1">
                      <span className="text-zinc-400">Tipo de Conexión:</span>
                      <span className="text-zinc-200">{selectedDevice.connection_type || 'Fibra GPON On-Net'}</span>
                    </div>
                  </div>
                </div>

                <div className="bg-[#121212] border border-zinc-800 rounded-2xl p-4 space-y-3">
                  <h4 className="text-xs font-bold text-zinc-300 uppercase tracking-wider flex items-center gap-2">
                    <Activity className="w-4 h-4 text-emerald-400" />
                    Telemetría y Tiempo de Conexión
                  </h4>

                  <div className="space-y-2 text-xs">
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">Duración de Sesión:</span>
                      <span className="font-mono text-emerald-400 font-bold">
                        {getLiveDurationFormatted(selectedDevice)}
                      </span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">Último Latido (Ping):</span>
                      <span className="font-mono text-zinc-200">
                        {selectedDevice.last_heartbeat_at
                          ? new Date(selectedDevice.last_heartbeat_at).toLocaleTimeString()
                          : 'Hace un momento'}
                      </span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-zinc-800/60">
                      <span className="text-zinc-400">ID Único de Dispositivo:</span>
                      <span className="font-mono text-zinc-400 truncate max-w-[150px]">{selectedDevice.device_id}</span>
                    </div>
                    <div className="flex justify-between py-1">
                      <span className="text-zinc-400">Comando Remoto:</span>
                      <span className="font-mono text-amber-400">{selectedDevice.remote_command || 'Ninguno'}</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* User-Agent Crudo en Bloque Monospace */}
              <div className="bg-[#121212] border border-zinc-800 rounded-2xl p-4 space-y-1.5">
                <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block">
                  User-Agent Oficial Detectado
                </span>
                <div className="font-mono text-[11px] text-zinc-300 bg-black/40 p-3 rounded-xl border border-zinc-800/80 break-all select-text">
                  {selectedDevice.user_agent || 'Mozilla/5.0 (SmartTV; FlixHN Client)'}
                </div>
              </div>
            </div>

            {/* Footer de Controles Remotos del Modal */}
            <div className="p-5 border-t border-zinc-800 bg-[#141414] flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <button
                  onClick={() =>
                    setAliasModal({
                      open: true,
                      device: selectedDevice,
                      newName: selectedDevice.custom_name,
                    })
                  }
                  className="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white transition-colors cursor-pointer"
                >
                  <Edit3 className="w-3.5 h-3.5" />
                  <span>Editar Alias</span>
                </button>

                <button
                  onClick={() => handleToggleBlock(selectedDevice)}
                  className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                    selectedDevice.is_blocked
                      ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 hover:bg-emerald-500/30'
                      : 'bg-rose-500/15 text-rose-400 border border-rose-500/30 hover:bg-rose-500/25'
                  }`}
                >
                  {selectedDevice.is_blocked ? (
                    <>
                      <ShieldCheck className="w-3.5 h-3.5" />
                      <span>Desbloquear Acceso</span>
                    </>
                  ) : (
                    <>
                      <ShieldAlert className="w-3.5 h-3.5" />
                      <span>Bloquear Acceso</span>
                    </>
                  )}
                </button>

                <button
                  onClick={() => handleTerminateSession(selectedDevice)}
                  className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-700 transition-colors cursor-pointer"
                >
                  <LogOut className="w-3.5 h-3.5 text-zinc-400" />
                  <span>Expulsar Sesión</span>
                </button>
              </div>

              <div className="flex items-center gap-2">
                {!selectedDevice.is_blocked && (
                  <button
                    onClick={() =>
                      handlePlaybackControl(
                        selectedDevice,
                        selectedDevice.playback_state === 'playing' ? 'pause' : 'resume'
                      )
                    }
                    className={`inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white transition-all shadow-md cursor-pointer ${
                      selectedDevice.playback_state === 'playing'
                        ? 'bg-amber-600 hover:bg-amber-500'
                        : 'bg-[#E50914] hover:bg-[#b80710]'
                    }`}
                  >
                    {selectedDevice.playback_state === 'playing' ? (
                      <>
                        <Pause className="w-3.5 h-3.5 fill-current" />
                        <span>Pausar Stream</span>
                      </>
                    ) : (
                      <>
                        <Play className="w-3.5 h-3.5 fill-current" />
                        <span>Reanudar Stream</span>
                      </>
                    )}
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL 2: CONFIRMACIÓN ESTILIZADA (Cero alertas nativas) ================= */}
      {confirmModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity"
            onClick={() => setConfirmModal((prev) => ({ ...prev, open: false }))}
          />

          <div className="relative w-full max-w-md bg-[#181818] border border-zinc-800 rounded-3xl p-6 shadow-2xl z-10 space-y-4 animate-scale-in">
            <div className="flex items-center gap-3">
              <div
                className={`w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 ${
                  confirmModal.isDanger
                    ? 'bg-rose-500/15 border border-rose-500/30 text-rose-400'
                    : 'bg-amber-500/15 border border-amber-500/30 text-amber-400'
                }`}
              >
                {confirmModal.isDanger ? <AlertCircle className="w-5 h-5" /> : <Info className="w-5 h-5" />}
              </div>
              <div>
                <h3 className="text-base font-bold text-white">{confirmModal.title}</h3>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">{confirmModal.message}</p>

            <div className="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-800">
              <button
                onClick={() => setConfirmModal((prev) => ({ ...prev, open: false }))}
                className="px-4 py-2 rounded-xl text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white transition-colors cursor-pointer"
              >
                {confirmModal.cancelText}
              </button>
              <button
                onClick={() => {
                  const act = confirmModal.action;
                  setConfirmModal((prev) => ({ ...prev, open: false }));
                  if (act) act();
                }}
                className={`px-4 py-2 rounded-xl text-xs font-bold text-white shadow-md transition-all cursor-pointer ${
                  confirmModal.isDanger
                    ? 'bg-rose-600 hover:bg-rose-700'
                    : 'bg-[#E50914] hover:bg-[#b80710]'
                }`}
              >
                {confirmModal.confirmText}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL 3: EDITAR ALIAS DEL DISPOSITIVO ================= */}
      {aliasModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity"
            onClick={() => setAliasModal({ open: false, device: null, newName: '' })}
          />

          <form
            onSubmit={handleSaveAlias}
            className="relative w-full max-w-md bg-[#181818] border border-zinc-800 rounded-3xl p-6 shadow-2xl z-10 space-y-4 animate-scale-in"
          >
            <div className="flex items-center justify-between">
              <h3 className="text-base font-bold text-white flex items-center gap-2">
                <Edit3 className="w-4 h-4 text-[#E50914]" />
                Renombrar Dispositivo
              </h3>
              <button
                type="button"
                onClick={() => setAliasModal({ open: false, device: null, newName: '' })}
                className="text-zinc-400 hover:text-white p-1 rounded transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <p className="text-xs text-zinc-400">
              Asigna un alias representativo para identificar rápidamente este equipo en la red (ej.{' '}
              <em>"Smart TV Sala - Familia Pérez"</em>).
            </p>

            <div>
              <label className="text-[11px] font-bold text-zinc-400 uppercase tracking-wider block mb-1.5">
                Alias / Nombre
              </label>
              <input
                type="text"
                value={aliasModal.newName}
                onChange={(e) => setAliasModal((prev) => ({ ...prev, newName: e.target.value }))}
                className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914]"
                placeholder="Nombre del dispositivo..."
                autoFocus
                required
              />
            </div>

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setAliasModal({ open: false, device: null, newName: '' })}
                className="px-4 py-2 rounded-xl text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="submit"
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer"
              >
                Guardar Alias
              </button>
            </div>
          </form>
        </div>
      )}

      {/* ================= NOTIFICACIONES TOAST (Cero alertas nativas) ================= */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 flex items-start gap-3 p-4 rounded-2xl shadow-2xl max-w-md w-full border backdrop-blur-md animate-slide-up transition-all bg-[#181818]/95 border-zinc-700 text-zinc-100">
          <div className="shrink-0 mt-0.5">
            {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400" />}
            {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400" />}
            {toast.type === 'info' && <RefreshCw className="w-5 h-5 text-red-500 animate-spin" />}
          </div>

          <div className="flex-1 min-w-0">
            {toast.title && <h4 className="text-xs font-bold text-zinc-100 uppercase tracking-wide">{toast.title}</h4>}
            <p className="text-xs text-zinc-300 mt-0.5 leading-relaxed">{toast.message}</p>
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
